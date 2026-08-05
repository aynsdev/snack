import { useEchoPresence } from '@laravel/echo-react';
import type { InfiniteData } from '@tanstack/react-query';
import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useReactions } from '@/hooks/use-reactions';
import { ApiError, api } from '@/lib/api';
import { events } from '@/lib/echo';
import { createNonce } from '@/lib/nonce';
import { timelineKey } from '@/lib/query-keys';
import {
    destroy as messageDestroy,
    update as messageUpdate,
} from '@/routes/messages';
import type {
    Message,
    MessageDeletedEvent,
    MessageEvent,
    MessagePage,
    PendingMessage,
    ReactionChangedEvent,
    SnackUser,
    ThreadActivityEvent,
} from '@/types';

/**
 * Where a timeline reads and writes. Supplied by the caller so this hook knows
 * nothing about channels or conversations.
 */
export type TimelineEndpoints = {
    /** The broadcast channel to follow, from `channels` in lib/echo.ts. */
    broadcastChannel: string;
    /** A page of history older than `cursor`, or the newest page for null. */
    history: (cursor: number | null) => string;
    send: () => string;
    /** Null when this container has no read state to advance. */
    markRead: (() => string) | null;
};

/** The cache entry behind one timeline: newest page first, older pages after. */
type TimelineData = InfiniteData<MessagePage, number | null>;

/**
 * The state of one timeline — a channel's or a conversation's.
 *
 * # Where the messages live
 *
 * In the TanStack Query cache, as one infinite query per container, keyed by its
 * broadcast channel. Page 0 is the newest page and every page after it is older,
 * which is the order the server paginates in.
 *
 * The cache is the *only* copy. Three sources write to a timeline — paginated
 * history, messages arriving over the WebSocket, and the user's own sends before
 * the server has confirmed them — and the failure this design exists to prevent
 * is any two of them holding separate lists that drift. Arrivals, edits,
 * deletions, and reactions are therefore written into the cache with
 * `setQueryData` rather than into component state beside it.
 *
 * Living in the cache rather than in `useState` also means the pages survive
 * this hook unmounting: walking from a channel to a DM and back returns the
 * reader to the history they had scrolled through, instead of to the newest page
 * and a spinner. That is the whole reason for the move.
 *
 * The socket is what keeps a timeline fresh, so the query never refetches on its
 * own — see lib/query-client.ts for why every automatic refetch is disabled.
 *
 * # Reconciling optimistic sends
 *
 * A sent message is shown immediately as a `PendingMessage` keyed by a nonce,
 * held in component state because it is not server data and has no id to cache
 * it under. It is replaced when the POST returns the real row. The broadcast is
 * *not* suppressed for the author — their other tabs need it — so the same
 * message can arrive twice, by two routes, in either order. De-duplication is by
 * id, which is correct regardless of which lands first.
 *
 * # Why one hook for both containers
 *
 * Every hard part above is identical for a DM and a channel, and all of it is
 * the kind of thing that is subtly wrong rather than obviously broken when
 * duplicated: an ordering rule, a de-duplication rule, a rollback. The two
 * containers differ only in which URLs they talk to and which channel they
 * follow, so that is all the caller supplies.
 */
export function useMessageTimeline({
    endpoints,
    workspaceSlug,
    initial,
    currentUser,
    onMessageReceived,
}: {
    endpoints: TimelineEndpoints;
    workspaceSlug: string;
    /** The newest page, rendered into the page's Inertia props by the server. */
    initial: MessagePage;
    currentUser: SnackUser | null;
    /** Called for messages from other people, for the notification sound. */
    onMessageReceived?: (message: Message) => void;
}) {
    const queryClient = useQueryClient();
    const [pending, setPending] = useState<PendingMessage[]>([]);

    const queryKey = useMemo(
        () => timelineKey(endpoints.broadcastChannel),
        [endpoints.broadcastChannel],
    );

    const { data, fetchNextPage, hasNextPage, isFetchingNextPage } =
        useInfiniteQuery({
            queryKey,
            queryFn: ({ pageParam }) =>
                api.get<MessagePage>(endpoints.history(pageParam)),

            // Null is "the newest page", which is what the endpoint returns
            // when no cursor is given. It is only ever fetched if the cache
            // has been evicted — the first page arrives as an Inertia prop.
            initialPageParam: null as number | null,
            getNextPageParam: (last: MessagePage) =>
                last.has_more ? last.next_cursor : undefined,
            initialData: { pages: [initial], pageParams: [null] },
        });

    /*
     * Fold the server-rendered page into whatever the cache already holds.
     *
     * `initialData` is ignored once there is a cache entry, so without this a
     * reader returning to a busy channel would see the history they left and
     * none of what the server just rendered for them. Merged rather than
     * substituted: page 0 also holds messages that arrived over the socket
     * after this prop was built, and replacing it would drop them.
     *
     * # Why the signature, and not the prop
     *
     * Depending on `initial` alone would make this run whenever the caller
     * happened to rebuild that object — which for a page rendering its props
     * inline is *every render*. Each run would restore page 0 to the
     * server-rendered set, so every arrival, edit, and deletion the socket had
     * applied since would be undone one render later. Content decides instead:
     * the same page rebuilt is the same page.
     */
    const signature = [
        initial.messages.length,
        initial.messages[0]?.id ?? 0,
        initial.messages.at(-1)?.id ?? 0,
        initial.next_cursor ?? 0,
    ].join(':');

    const seeded = useRef<string | null>(null);

    useEffect(() => {
        if (seeded.current === signature) {
            return;
        }

        seeded.current = signature;

        queryClient.setQueryData<TimelineData>(queryKey, (current) => {
            if (current === undefined) {
                return { pages: [initial], pageParams: [null] };
            }

            const [newest, ...older] = current.pages;

            return {
                ...current,
                pages: [
                    {
                        // The freshly rendered page decides how much history is
                        // behind it; the cached copy's cursor describes a page
                        // that no longer has the same last row.
                        ...initial,
                        messages: mergeById(newest.messages, initial.messages),
                    },
                    ...older,
                ],
            };
        });
    }, [initial, signature, queryClient, queryKey]);

    /**
     * Every page flattened into one list, oldest first, de-duplicated by id.
     *
     * Ordering by id rather than timestamp because ids are monotonic and
     * assigned by one authority; two messages a millisecond apart can carry the
     * same timestamp, and clock skew across app servers can invert them.
     *
     * De-duplicating here rather than trusting the pages to be disjoint is what
     * makes the merge above safe: a message can legitimately appear in both the
     * page the server just rendered and the page the cache already held.
     */
    const messages = useMemo(() => {
        const byId = new Map<number, Message>();

        for (const page of data.pages) {
            for (const message of page.messages) {
                byId.set(message.id, message);
            }
        }

        return [...byId.values()].sort((left, right) => left.id - right.id);
    }, [data]);

    /**
     * The newest id the client is displaying.
     *
     * Read during render rather than recorded by an effect, and that is the
     * whole fix for a badge that would not clear. Effects run child-first, and
     * the component that decides the reader is at the bottom — {@see MessageList}
     * — is a child of whoever owns this hook. On mount it called `markAsRead`
     * before any effect here could run, so the callback found nothing to report
     * and returned: opening a channel or a DM never advanced the read pointer,
     * and the sidebar went on counting messages the reader was looking at until
     * the next arrival happened to re-fire the effect.
     */
    const newestLoadedId = messages.at(-1)?.id ?? null;

    /**
     * The pointer this timeline has already reported.
     *
     * Read syncs are cheap but not free, and the reader reaches the bottom on
     * every scroll event once they are there; this keeps the request to the
     * moments the pointer actually moves.
     */
    const lastSyncedId = useRef<number | null>(null);

    /** Rewrite the cached pages. A no-op when the timeline is not cached. */
    const writePages = useCallback(
        (rewrite: (pages: MessagePage[]) => MessagePage[]) => {
            queryClient.setQueryData<TimelineData>(queryKey, (current) =>
                current === undefined
                    ? current
                    : { ...current, pages: rewrite(current.pages) },
            );
        },
        [queryClient, queryKey],
    );

    /** Apply a change to whichever page holds this message, if any is loaded. */
    const patch = useCallback(
        (messageId: number, change: (message: Message) => Message) => {
            writePages((pages) =>
                pages.map((page) =>
                    page.messages.some((message) => message.id === messageId)
                        ? {
                              ...page,
                              messages: page.messages.map((message) =>
                                  message.id === messageId
                                      ? change(message)
                                      : message,
                              ),
                          }
                        : page,
                ),
            );
        },
        [writePages],
    );

    /**
     * Insert or replace a message.
     *
     * A message already loaded is replaced where it sits; a new one belongs at
     * the newest end, which is page 0. Nothing re-sorts here — the flattening
     * above orders the whole timeline by id, so no writer has to know where in
     * the list its message goes.
     */
    const upsert = useCallback(
        (incoming: Message) => {
            writePages((pages) => {
                const known = pages.some((page) =>
                    page.messages.some((message) => message.id === incoming.id),
                );

                if (known) {
                    return pages.map((page) => ({
                        ...page,
                        messages: page.messages.map((message) =>
                            message.id === incoming.id
                                ? /*
                                   * Keep the viewer-specific fields from the
                                   * HTTP-loaded copy. A broadcast cannot carry
                                   * either — there is no single viewer to
                                   * compute them for — so taking the incoming
                                   * `undefined` would make the author's own edit
                                   * controls vanish, and un-bookmark a message
                                   * they had saved, the moment it echoed back.
                                   */
                                  {
                                      ...incoming,
                                      can: incoming.can ?? message.can,
                                      is_saved:
                                          incoming.is_saved ?? message.is_saved,
                                  }
                                : message,
                        ),
                    }));
                }

                const [newest, ...older] = pages;

                return [
                    { ...newest, messages: [...newest.messages, incoming] },
                    ...older,
                ];
            });
        },
        [writePages],
    );

    /**
     * Replace one message's reaction summary.
     *
     * A message that is not currently loaded is ignored: its reactions are
     * already correct in whatever page fetches it later.
     */
    const applyReactions = useCallback(
        (messageId: number, reactions: Message['reactions']) => {
            patch(messageId, (message) => ({ ...message, reactions }));
        },
        [patch],
    );

    /**
     * Flip a message's private save flag.
     *
     * Nothing broadcasts this — a save is visible only to the person who made
     * it — so the only copy that needs updating is the one on screen.
     */
    const applySaved = useCallback(
        (messageId: number, isSaved: boolean) => {
            patch(messageId, (message) => ({ ...message, is_saved: isSaved }));
        },
        [patch],
    );

    const { toggle: react } = useReactions({
        workspaceSlug,
        currentUserId: currentUser?.id ?? null,
        apply: applyReactions,
    });

    // -- Real time ----------------------------------------------------------

    useEchoPresence<MessageEvent>(
        endpoints.broadcastChannel,
        events.messageSent,
        (payload) => {
            upsert(payload.message);

            if (payload.message.sender?.id !== currentUser?.id) {
                onMessageReceived?.(payload.message);
            }
        },
        [endpoints.broadcastChannel, currentUser?.id],
    );

    useEchoPresence<MessageEvent>(
        endpoints.broadcastChannel,
        events.messageUpdated,
        (payload) => upsert(payload.message),
        [endpoints.broadcastChannel],
    );

    useEchoPresence<MessageDeletedEvent>(
        endpoints.broadcastChannel,
        events.messageDeleted,
        (payload) =>
            writePages((pages) =>
                pages.map((page) => ({
                    ...page,
                    messages: page.messages.filter((m) => m.id !== payload.id),
                })),
            ),
        [endpoints.broadcastChannel],
    );

    /*
     * A reaction changed on a message in this container. The payload is the
     * message's whole reaction state, so applying it is a replacement rather
     * than arithmetic — a client that missed an earlier broadcast is corrected
     * by this one instead of compounding the drift.
     */
    useEchoPresence<ReactionChangedEvent>(
        endpoints.broadcastChannel,
        events.reactionChanged,
        (payload) => applyReactions(payload.message_id, payload.reactions),
        [endpoints.broadcastChannel],
    );

    /*
     * A thread somewhere in this container gained or lost a reply. The reply
     * itself goes to `thread.{root}` and never arrives here — only the parent's
     * "N replies" affordance changes, so only those two fields are touched. A
     * message not currently loaded is ignored: its count is already correct in
     * whatever page fetches it later.
     */
    useEchoPresence<ThreadActivityEvent>(
        endpoints.broadcastChannel,
        events.threadActivity,
        (payload) =>
            patch(payload.root_id, (message) => ({
                ...message,
                reply_count: payload.reply_count,
                last_reply_at: payload.last_reply_at,
            })),
        [endpoints.broadcastChannel],
    );

    // -- History ------------------------------------------------------------

    /**
     * Fetch the page before the oldest one loaded.
     *
     * The guard against re-entry is the query's own: a second call while a page
     * is in flight resolves to the same request rather than fetching it twice.
     */
    const loadOlder = useCallback(() => {
        if (!hasNextPage || isFetchingNextPage) {
            return;
        }

        void fetchNextPage();
    }, [fetchNextPage, hasNextPage, isFetchingNextPage]);

    // -- Mutations ----------------------------------------------------------

    const send = useCallback(
        async (body: string, attachmentIds: number[] = []) => {
            if (currentUser === null) {
                return;
            }

            const nonce = createNonce();

            setPending((current) => [
                ...current,
                {
                    nonce,
                    body,
                    created_at: new Date().toISOString(),
                    sender: currentUser,
                    failed: false,
                    attachmentCount: attachmentIds.length,
                    attachmentIds,
                },
            ]);

            try {
                const { message } = await api.post<{ message: Message }>(
                    endpoints.send(),
                    /*
                     * The ids name uploads the server already holds, and it
                     * re-verifies every one of them against this workspace and
                     * this uploader before claiming it — see ResolvesAttachments.
                     */
                    { body, attachment_ids: attachmentIds },
                );

                upsert(message);
                setPending((current) =>
                    current.filter((p) => p.nonce !== nonce),
                );
            } catch {
                /*
                 * The optimistic message stays on screen, marked failed, rather
                 * than vanishing. Silently dropping it is the worst outcome:
                 * the author saw their message appear and has already moved on,
                 * believing it was sent.
                 */
                setPending((current) =>
                    current.map((p) =>
                        p.nonce === nonce ? { ...p, failed: true } : p,
                    ),
                );
            }
        },
        [currentUser, endpoints, upsert],
    );

    const retry = useCallback(
        (nonce: string) => {
            const failed = pending.find((p) => p.nonce === nonce);

            if (failed === undefined) {
                return;
            }

            setPending((current) => current.filter((p) => p.nonce !== nonce));

            /*
             * The same ids are re-sent. A failed send claimed nothing, so the
             * uploads are still unattached and still this user's — the retry
             * claims them exactly as the first attempt would have.
             */
            void send(failed.body, failed.attachmentIds);
        },
        [pending, send],
    );

    const discard = useCallback((nonce: string) => {
        setPending((current) => current.filter((p) => p.nonce !== nonce));
    }, []);

    const edit = useCallback(
        async (id: number, body: string) => {
            const previous = messages.find((m) => m.id === id);

            // Applied locally first so the edit lands with the keystroke, then
            // rolled back if the server disagrees.
            patch(id, (message) => ({ ...message, body, is_edited: true }));

            try {
                const { message } = await api.patch<{ message: Message }>(
                    messageUpdate.url({
                        workspace: workspaceSlug,
                        message: id,
                    }),
                    { body },
                );

                upsert(message);
            } catch (error) {
                if (previous !== undefined) {
                    upsert(previous);
                }

                throw error;
            }
        },
        [messages, patch, upsert, workspaceSlug],
    );

    const remove = useCallback(
        async (id: number) => {
            const previous = messages.find((m) => m.id === id);

            writePages((pages) =>
                pages.map((page) => ({
                    ...page,
                    messages: page.messages.filter((m) => m.id !== id),
                })),
            );

            try {
                await api.delete(
                    messageDestroy.url({
                        workspace: workspaceSlug,
                        message: id,
                    }),
                );
            } catch (error) {
                // Restoring matters more here than anywhere else: a message
                // that looks deleted but is not is a message the author thinks
                // they retracted.
                if (previous !== undefined) {
                    upsert(previous);
                }

                throw error;
            }
        },
        [messages, upsert, workspaceSlug, writePages],
    );

    /**
     * Tell the server the reader has seen everything currently displayed.
     *
     * Fire-and-forget: a failed read sync corrects itself on the next call, and
     * surfacing an error for it would be noise the reader can do nothing about.
     * The pointer is released again on failure so that "the next call" is not
     * blocked by the attempt that failed.
     */
    const markAsRead = useCallback(() => {
        const url = endpoints.markRead;

        if (newestLoadedId === null || url === null) {
            return;
        }

        if (newestLoadedId <= (lastSyncedId.current ?? 0)) {
            return;
        }

        lastSyncedId.current = newestLoadedId;

        void api
            .post(url(), { last_read_message_id: newestLoadedId })
            .catch((error: unknown) => {
                lastSyncedId.current = null;

                if (!(error instanceof ApiError)) {
                    throw error;
                }
            });
    }, [endpoints, newestLoadedId]);

    return {
        messages,
        pending,
        hasMore: hasNextPage,
        loadingOlder: isFetchingNextPage,
        loadOlder,
        send,
        retry,
        discard,
        edit,
        remove,
        react,
        applySaved,
        markAsRead,
    };
}

/**
 * Union of two pages of messages by id, with the second winning a tie.
 *
 * Used to fold a freshly rendered page into a cached one, where "the same
 * message" may carry a newer body, a newer reaction summary, or the
 * viewer-specific fields only an HTTP load can compute.
 */
function mergeById(existing: Message[], incoming: Message[]): Message[] {
    const byId = new Map(existing.map((message) => [message.id, message]));

    for (const message of incoming) {
        byId.set(message.id, message);
    }

    return [...byId.values()];
}
