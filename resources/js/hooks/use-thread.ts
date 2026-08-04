import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useReactions } from '@/hooks/use-reactions';
import { ApiError, api } from '@/lib/api';
import { channels, events } from '@/lib/echo';
import { createNonce } from '@/lib/nonce';
import {
    destroy as messageDestroy,
    update as messageUpdate,
} from '@/routes/messages';
import { show as threadShow } from '@/routes/threads';
import { store as threadRead } from '@/routes/threads/read';
import {
    destroy as unfollowThread,
    store as followThread,
} from '@/routes/threads/subscription';
import type {
    Message,
    MessageDeletedEvent,
    MessageEvent,
    PendingMessage,
    ReactionChangedEvent,
    SnackUser,
    ThreadPage,
    ThreadSubscriptionState,
} from '@/types';

/**
 * One thread's replies.
 *
 * # Why this is not useMessageTimeline
 *
 * The two look alike and behave differently in every way that matters. A
 * timeline pages *backwards* from the newest message and prepends; a thread
 * pages *forwards* from the root and appends. A timeline subscribes to a
 * presence channel that renders a roster; a thread subscribes to a private one
 * with no roster at all. A timeline's read pointer lives on a membership row
 * that always exists; a thread's lives on a subscription row that may not exist
 * yet and must not be created just because someone looked.
 *
 * Forcing them together would mean a hook whose every branch asks which of the
 * two it is — so the genuinely shared part (optimistic sends reconciled by
 * nonce, de-duplication by id) is repeated deliberately, and the parts that
 * differ are simply written once each.
 *
 * # Sending
 *
 * Replies post to the *container's* send endpoint with a `parent_message_id`,
 * not to a thread-specific one. There is one send path in the app, so a reply
 * cannot pick up different validation or a different idea of its workspace than
 * the message it answers.
 *
 * The hook assumes it is remounted when the thread changes; callers mount it
 * with `key={rootId}`, matching {@see useMessageTimeline}. That is what lets the
 * initial state simply *be* "loading" rather than being reset inside an effect,
 * which would cascade a render on every open.
 */
export function useThread({
    rootId,
    workspaceSlug,
    sendUrl,
    currentUser,
}: {
    /** The message the panel was opened on. May be a reply; the server normalizes. */
    rootId: number;
    workspaceSlug: string;
    /** The container's message-store URL — see the note above on one send path. */
    sendUrl: string;
    currentUser: SnackUser | null;
}) {
    const [root, setRoot] = useState<Message | null>(null);
    const [replies, setReplies] = useState<Message[]>([]);
    const [pending, setPending] = useState<PendingMessage[]>([]);
    const [subscription, setSubscription] = useState<ThreadSubscriptionState>({
        is_subscribed: false,
        last_read_reply_id: null,
    });
    const [loading, setLoading] = useState(true);
    const [hasMore, setHasMore] = useState(false);
    const [cursor, setCursor] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    /*
     * The id the panel actually subscribes to and posts against. The prop may
     * name a reply — a link out of a mention does — and the server answers with
     * the real root, which is what the broadcast channel is keyed by.
     */
    const [resolvedRootId, setResolvedRootId] = useState(rootId);

    const newestSeen = useRef<number | null>(null);

    /**
     * Every reply id this panel has ever displayed.
     *
     * Distinguishes a genuinely new reply from a redelivery, which decides
     * whether the root's tally moves. The author's own reply arrives twice — the
     * POST response and their own broadcast — and an edit re-delivers a reply
     * that was already counted, so counting on arrival would inflate it.
     */
    const seenReplies = useRef(new Set<number>());

    /**
     * Move the root's reply tally, which the panel renders above the list.
     *
     * The panel has to do this itself: `ThreadActivity` — the event that carries
     * a new count — is addressed to the *container*, because its audience is
     * everyone looking at the channel. Nobody sends it to the thread, so a panel
     * that waited for one would sit on "No replies yet" with a reply beneath it.
     */
    const countReply = useCallback((delta: number, newest: Message | null) => {
        setRoot((current) =>
            current === null
                ? current
                : {
                      ...current,
                      reply_count: Math.max(current.reply_count + delta, 0),
                      last_reply_at:
                          newest?.created_at ?? current.last_reply_at,
                      last_reply_id: newest?.id ?? current.last_reply_id,
                  },
        );
    }, []);

    /**
     * Insert or replace a reply, keeping the list ordered by id.
     *
     * By id rather than timestamp for the same reason the timeline does it: ids
     * are monotonic and assigned by one authority, while two replies a
     * millisecond apart can carry the same timestamp.
     */
    const upsert = useCallback(
        (incoming: Message) => {
            if (!seenReplies.current.has(incoming.id)) {
                seenReplies.current.add(incoming.id);
                countReply(1, incoming);
            }

            setReplies((current) => {
                const index = current.findIndex((m) => m.id === incoming.id);

                if (index !== -1) {
                    const next = [...current];
                    // A broadcast cannot carry per-viewer abilities, so the
                    // ones from the HTTP copy are kept — otherwise the author's
                    // own edit controls vanish when their reply echoes back.
                    next[index] = {
                        ...incoming,
                        can: incoming.can ?? current[index].can,
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
        },
        [countReply],
    );

    // -- Loading -------------------------------------------------------------

    useEffect(() => {
        const controller = new AbortController();

        api.get<ThreadPage>(
            threadShow.url({ workspace: workspaceSlug, message: rootId }),
            controller.signal,
        )
            .then((page) => {
                setRoot(page.root);
                setResolvedRootId(page.root.id);
                setReplies(page.replies);
                setSubscription(page.subscription);
                setHasMore(page.has_more);
                setCursor(page.next_cursor);
                newestSeen.current = page.replies.at(-1)?.id ?? null;

                // Seeded, not counted: the root already arrived with the real
                // tally, so these must not be added to it again.
                page.replies.forEach((reply) =>
                    seenReplies.current.add(reply.id),
                );
            })
            .catch((cause: unknown) => {
                // An aborted request is the panel closing, not a failure.
                if (controller.signal.aborted) {
                    return;
                }

                setError(
                    cause instanceof ApiError
                        ? cause.userMessage
                        : 'This thread could not be loaded.',
                );
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [rootId, workspaceSlug]);

    /**
     * Replace one message's reaction summary, in the root or in a reply.
     *
     * The root is checked too: it is rendered by this panel and can be reacted
     * to here, but it lives outside the reply list.
     */
    const applyReactions = useCallback(
        (messageId: number, reactions: Message['reactions']) => {
            setRoot((current) =>
                current !== null && current.id === messageId
                    ? { ...current, reactions }
                    : current,
            );

            setReplies((current) =>
                current.map((reply) =>
                    reply.id === messageId ? { ...reply, reactions } : reply,
                ),
            );
        },
        [],
    );

    const { toggle: react } = useReactions({
        workspaceSlug,
        currentUserId: currentUser?.id ?? null,
        apply: applyReactions,
    });

    // -- Real time -----------------------------------------------------------

    /*
     * A private channel, not a presence one: a thread has no roster to render,
     * and its authorization callback answers with a bool rather than a member
     * payload. Subscribing as presence would simply never connect.
     */
    useEcho<MessageEvent>(
        channels.thread(resolvedRootId),
        events.messageSent,
        (payload) => upsert(payload.message),
        [resolvedRootId],
    );

    useEcho<MessageEvent>(
        channels.thread(resolvedRootId),
        events.messageUpdated,
        (payload) => upsert(payload.message),
        [resolvedRootId],
    );

    useEcho<MessageDeletedEvent>(
        channels.thread(resolvedRootId),
        events.messageDeleted,
        (payload) => {
            if (seenReplies.current.delete(payload.id)) {
                countReply(-1, null);
            }

            setReplies((current) => current.filter((m) => m.id !== payload.id));
        },
        [resolvedRootId, countReply],
    );

    /*
     * Reactions on a reply arrive here, since a reply's events are routed to the
     * thread rather than the container. Reactions on the *root* arrive on the
     * container channel instead — the root is a timeline message — so the panel
     * only learns about those when it is reopened. Both are the same replacement
     * either way, so nothing drifts.
     */
    useEcho<ReactionChangedEvent>(
        channels.thread(resolvedRootId),
        events.reactionChanged,
        (payload) => applyReactions(payload.message_id, payload.reactions),
        [resolvedRootId],
    );

    // -- History -------------------------------------------------------------

    const loadMore = useCallback(async () => {
        if (!hasMore || cursor === null) {
            return;
        }

        const page = await api.get<ThreadPage>(
            threadShow.url(
                { workspace: workspaceSlug, message: resolvedRootId },
                { query: { after: cursor } },
            ),
        );

        setReplies((current) => {
            // The cursor makes overlap impossible in principle, but a
            // double-fire while a request is in flight would duplicate a page.
            const known = new Set(current.map((m) => m.id));

            return [
                ...current,
                ...page.replies.filter((m) => !known.has(m.id)),
            ];
        });

        setHasMore(page.has_more);
        setCursor(page.next_cursor);
    }, [cursor, hasMore, resolvedRootId, workspaceSlug]);

    // -- Mutations -----------------------------------------------------------

    const reply = useCallback(
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
                    sendUrl,
                    {
                        body,
                        parent_message_id: resolvedRootId,
                        attachment_ids: attachmentIds,
                    },
                );

                upsert(message);
                setPending((current) =>
                    current.filter((p) => p.nonce !== nonce),
                );

                // Replying subscribes you server-side; reflect that immediately
                // rather than making the follow control wait for a reload.
                setSubscription((current) => ({
                    ...current,
                    is_subscribed: true,
                }));
            } catch {
                /*
                 * The optimistic reply stays on screen, marked failed. Dropping
                 * it silently is the worst outcome: the author watched it
                 * appear and has already moved on.
                 */
                setPending((current) =>
                    current.map((p) =>
                        p.nonce === nonce ? { ...p, failed: true } : p,
                    ),
                );
            }
        },
        [currentUser, resolvedRootId, sendUrl, upsert],
    );

    const retry = useCallback(
        (nonce: string) => {
            const failed = pending.find((p) => p.nonce === nonce);

            if (failed === undefined) {
                return;
            }

            setPending((current) => current.filter((p) => p.nonce !== nonce));
            void reply(failed.body);
        },
        [pending, reply],
    );

    const discard = useCallback((nonce: string) => {
        setPending((current) => current.filter((p) => p.nonce !== nonce));
    }, []);

    const edit = useCallback(
        async (id: number, body: string) => {
            const previous =
                replies.find((m) => m.id === id) ??
                (root?.id === id ? root : undefined);

            // Applied locally first so the edit lands with the keystroke, then
            // rolled back if the server disagrees.
            const apply = (next: Message) =>
                next.id === root?.id ? setRoot(next) : upsert(next);

            if (previous !== undefined) {
                apply({ ...previous, body, is_edited: true });
            }

            try {
                const { message } = await api.patch<{ message: Message }>(
                    messageUpdate.url({
                        workspace: workspaceSlug,
                        message: id,
                    }),
                    { body },
                );

                apply(message);
            } catch (cause) {
                if (previous !== undefined) {
                    apply(previous);
                }

                throw cause;
            }
        },
        [replies, root, upsert, workspaceSlug],
    );

    const remove = useCallback(
        async (id: number) => {
            const previous = replies.find((m) => m.id === id);

            if (seenReplies.current.delete(id)) {
                countReply(-1, null);
            }

            setReplies((current) => current.filter((m) => m.id !== id));

            try {
                await api.delete(
                    messageDestroy.url({
                        workspace: workspaceSlug,
                        message: id,
                    }),
                );
            } catch (cause) {
                // Restoring matters more here than anywhere else: a reply that
                // looks deleted but is not is one the author thinks they took
                // back.
                if (previous !== undefined) {
                    // upsert re-adds it to `seenReplies`, which restores the
                    // tally the optimistic removal just took off.
                    upsert(previous);
                }

                throw cause;
            }
        },
        [countReply, replies, upsert, workspaceSlug],
    );

    const setFollowing = useCallback(
        async (following: boolean) => {
            const previous = subscription;

            setSubscription((current) => ({
                ...current,
                is_subscribed: following,
            }));

            try {
                const args = {
                    workspace: workspaceSlug,
                    message: resolvedRootId,
                };

                const next = await (following
                    ? api.post<ThreadSubscriptionState>(followThread.url(args))
                    : api.delete<ThreadSubscriptionState>(
                          unfollowThread.url(args),
                      ));

                setSubscription(next);
            } catch (cause) {
                setSubscription(previous);

                throw cause;
            }
        },
        [resolvedRootId, subscription, workspaceSlug],
    );

    /**
     * Tell the server the reader has seen every reply currently displayed.
     *
     * Fire-and-forget: a failed read sync corrects itself on the next call, and
     * surfacing an error for it would be noise the reader can do nothing about.
     *
     * A no-op for someone who does not follow the thread — reading it writes no
     * subscription, since glancing at something is not asking to hear about it
     * forever.
     */
    const markAsRead = useCallback(() => {
        if (newestSeen.current === null || !subscription.is_subscribed) {
            return;
        }

        void api
            .post(
                threadRead.url({
                    workspace: workspaceSlug,
                    message: resolvedRootId,
                }),
                { last_read_reply_id: newestSeen.current },
            )
            /*
             * Reading the replies also stamps the inbox rows they raised, and
             * that count is server-owned — so it is asked for rather than
             * adjusted here. Channels and DMs get the same refresh from their
             * read broadcast; a thread has none, and the panel opens without a
             * navigation, so nothing else would ever re-read the badge.
             */
            .then(() => router.reload({ only: ['snack'] }))
            .catch((cause: unknown) => {
                if (!(cause instanceof ApiError)) {
                    throw cause;
                }
            });
    }, [resolvedRootId, subscription.is_subscribed, workspaceSlug]);

    return {
        root,
        replies,
        pending,
        subscription,
        loading,
        error,
        hasMore,
        loadMore,
        reply,
        retry,
        discard,
        edit,
        remove,
        react,
        setFollowing,
        markAsRead,
    };
}
