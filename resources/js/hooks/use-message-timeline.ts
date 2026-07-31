import { useEchoPresence } from '@laravel/echo-react';
import { useCallback, useRef, useState } from 'react';
import { useReactions } from '@/hooks/use-reactions';
import { ApiError, api } from '@/lib/api';
import { events } from '@/lib/echo';
import { createNonce } from '@/lib/nonce';
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
    /** A page of history older than `cursor`. */
    history: (cursor: number) => string;
    send: () => string;
    /** Null when this container has no read state to advance. */
    markRead: (() => string) | null;
};

/**
 * The state of one timeline — a channel's or a conversation's.
 *
 * # Why the client owns this list
 *
 * Three sources write to a timeline: paginated history, messages arriving over
 * the WebSocket, and the user's own sends before the server has confirmed them.
 * Inertia props are server-owned and replaced wholesale on each visit, so
 * holding the timeline in props would mean the three race each other and a
 * broadcast landing mid-request could erase a message. One store here is the
 * only place any of them can write.
 *
 * # Reconciling optimistic sends
 *
 * A sent message is shown immediately as a `PendingMessage` keyed by a nonce. It
 * is replaced when the POST returns the real row. The broadcast is *not*
 * suppressed for the author — their other tabs need it — so the same message can
 * arrive twice, by two routes, in either order. De-duplication is by id, which is
 * correct regardless of which lands first.
 *
 * # Why one hook for both containers
 *
 * Every hard part above is identical for a DM and a channel, and all of it is
 * the kind of thing that is subtly wrong rather than obviously broken when
 * duplicated: an ordering rule, a de-duplication rule, a rollback. The two
 * containers differ only in which URLs they talk to and which channel they
 * follow, so that is all the caller supplies.
 *
 * The hook assumes it is remounted when the container changes; callers mount it
 * with `key={container.id}` so switching resets state rather than leaking one
 * conversation's messages into another.
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
    initial: MessagePage;
    currentUser: SnackUser | null;
    /** Called for messages from other people, for the notification sound. */
    onMessageReceived?: (message: Message) => void;
}) {
    const [messages, setMessages] = useState<Message[]>(initial.messages);
    const [pending, setPending] = useState<PendingMessage[]>([]);
    const [cursor, setCursor] = useState<number | null>(initial.next_cursor);
    const [hasMore, setHasMore] = useState(initial.has_more);
    const [loadingOlder, setLoadingOlder] = useState(false);

    /*
     * The newest id the client has actually displayed, tracked in a ref so the
     * read-marking callback does not need to be rebuilt on every message.
     */
    const newestSeen = useRef<number | null>(
        initial.messages.at(-1)?.id ?? null,
    );

    /**
     * Insert or replace a message, keeping the list ordered by id.
     *
     * Ordering by id rather than timestamp because ids are monotonic and
     * assigned by one authority; two messages a millisecond apart can carry the
     * same timestamp, and clock skew across app servers can invert them.
     */
    const upsert = useCallback((incoming: Message) => {
        setMessages((current) => {
            const index = current.findIndex((m) => m.id === incoming.id);

            if (index !== -1) {
                const next = [...current];
                /*
                 * Keep the viewer-specific fields from the HTTP-loaded copy. A
                 * broadcast cannot carry either — there is no single viewer to
                 * compute them for — so taking the incoming `undefined` would
                 * make the author's own edit controls vanish, and un-bookmark a
                 * message they had saved, the moment it echoed back.
                 */
                next[index] = {
                    ...incoming,
                    can: incoming.can ?? current[index].can,
                    is_saved: incoming.is_saved ?? current[index].is_saved,
                };

                return next;
            }

            if (incoming.id > (newestSeen.current ?? 0)) {
                newestSeen.current = incoming.id;
            }

            const at = current.findIndex((m) => m.id > incoming.id);

            return at === -1
                ? [...current, incoming]
                : [...current.slice(0, at), incoming, ...current.slice(at)];
        });
    }, []);

    /**
     * Replace one message's reaction summary.
     *
     * A message that is not currently loaded is ignored: its reactions are
     * already correct in whatever page fetches it later.
     */
    const applyReactions = useCallback(
        (messageId: number, reactions: Message['reactions']) => {
            setMessages((current) =>
                current.map((message) =>
                    message.id === messageId
                        ? { ...message, reactions }
                        : message,
                ),
            );
        },
        [],
    );

    /**
     * Flip a message's private save flag.
     *
     * Nothing broadcasts this — a save is visible only to the person who made
     * it — so the only copy that needs updating is the one on screen.
     */
    const applySaved = useCallback((messageId: number, isSaved: boolean) => {
        setMessages((current) =>
            current.map((message) =>
                message.id === messageId
                    ? { ...message, is_saved: isSaved }
                    : message,
            ),
        );
    }, []);

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
            setMessages((current) =>
                current.filter((m) => m.id !== payload.id),
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
            setMessages((current) =>
                current.map((message) =>
                    message.id === payload.root_id
                        ? {
                              ...message,
                              reply_count: payload.reply_count,
                              last_reply_at: payload.last_reply_at,
                          }
                        : message,
                ),
            ),
        [endpoints.broadcastChannel],
    );

    // -- History ------------------------------------------------------------

    const loadOlder = useCallback(async () => {
        if (!hasMore || loadingOlder || cursor === null) {
            return;
        }

        setLoadingOlder(true);

        try {
            const page = await api.get<MessagePage>(endpoints.history(cursor));

            setMessages((current) => {
                // The cursor makes overlap impossible in principle, but a
                // double-fire while a request is in flight would otherwise
                // duplicate a whole page.
                const known = new Set(current.map((m) => m.id));

                return [
                    ...page.messages.filter((m) => !known.has(m.id)),
                    ...current,
                ];
            });

            setCursor(page.next_cursor);
            setHasMore(page.has_more);
        } finally {
            setLoadingOlder(false);
        }
    }, [cursor, endpoints, hasMore, loadingOlder]);

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
            setMessages((current) =>
                current.map((m) =>
                    m.id === id ? { ...m, body, is_edited: true } : m,
                ),
            );

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
        [messages, upsert, workspaceSlug],
    );

    const remove = useCallback(
        async (id: number) => {
            const previous = messages.find((m) => m.id === id);

            setMessages((current) => current.filter((m) => m.id !== id));

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
        [messages, upsert, workspaceSlug],
    );

    /**
     * Tell the server the reader has seen everything currently displayed.
     *
     * Fire-and-forget: a failed read sync corrects itself on the next call, and
     * surfacing an error for it would be noise the reader can do nothing about.
     */
    const markAsRead = useCallback(() => {
        const url = endpoints.markRead;

        if (newestSeen.current === null || url === null) {
            return;
        }

        void api
            .post(url(), { last_read_message_id: newestSeen.current })
            .catch((error: unknown) => {
                if (!(error instanceof ApiError)) {
                    throw error;
                }
            });
    }, [endpoints]);

    return {
        messages,
        pending,
        hasMore,
        loadingOlder,
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
