import { useEchoPresence } from '@laravel/echo-react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { ApiError, api } from '@/lib/api';
import { channels, events } from '@/lib/echo';
import {
    index as messageIndex,
    store as messageStore,
} from '@/routes/channels/messages';
import { store as markRead } from '@/routes/channels/read';
import {
    destroy as messageDestroy,
    update as messageUpdate,
} from '@/routes/messages';
import type {
    Channel,
    Message,
    MessageDeletedEvent,
    MessageEvent,
    MessagePage,
    PendingMessage,
    SnackUser,
} from '@/types';

/**
 * The state of one channel's timeline.
 *
 * # Why the client owns this list
 *
 * Three sources write to a channel timeline: paginated history, messages
 * arriving over the WebSocket, and the user's own sends before the server has
 * confirmed them. Inertia props are server-owned and replaced wholesale on each
 * visit, so holding the timeline in props would mean the three race each other
 * and a broadcast landing mid-request could erase a message. One reducer-ish
 * store here is the only place any of them can write.
 *
 * # Reconciling optimistic sends
 *
 * A sent message is shown immediately as a `PendingMessage` keyed by a nonce.
 * It is replaced when the POST returns the real row. The broadcast is *not*
 * suppressed for the author — their other tabs need it — so the same message
 * can arrive twice, by two routes, in either order. De-duplication is by id,
 * which is correct regardless of which lands first.
 *
 * The hook assumes it is remounted when the channel changes; the timeline is
 * mounted with `key={channel.id}` so switching channels resets state rather
 * than leaking one channel's messages into another.
 */
export function useChannelMessages({
    channel,
    workspaceSlug,
    initial,
    currentUser,
    onMessageReceived,
}: {
    channel: Channel;
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

    // Memoized so the callbacks below have a stable dependency; rebuilt inline
    // it would be a new object each render and invalidate every one of them.
    const routeArgs = useMemo(
        () => ({ workspace: workspaceSlug, channel: channel.slug }),
        [workspaceSlug, channel.slug],
    );

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
                // Keep the abilities from the HTTP-loaded copy: a broadcast
                // cannot carry them, and dropping them would make the author's
                // own edit controls vanish when their message echoes back.
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
    }, []);

    // -- Real time ----------------------------------------------------------

    useEchoPresence<MessageEvent>(
        channels.channel(channel.id),
        events.messageSent,
        (payload) => {
            upsert(payload.message);

            if (payload.message.sender?.id !== currentUser?.id) {
                onMessageReceived?.(payload.message);
            }
        },
        [channel.id, currentUser?.id],
    );

    useEchoPresence<MessageEvent>(
        channels.channel(channel.id),
        events.messageUpdated,
        (payload) => upsert(payload.message),
        [channel.id],
    );

    useEchoPresence<MessageDeletedEvent>(
        channels.channel(channel.id),
        events.messageDeleted,
        (payload) =>
            setMessages((current) =>
                current.filter((m) => m.id !== payload.id),
            ),
        [channel.id],
    );

    // -- History ------------------------------------------------------------

    const loadOlder = useCallback(async () => {
        if (!hasMore || loadingOlder || cursor === null) {
            return;
        }

        setLoadingOlder(true);

        try {
            const page = await api.get<MessagePage>(
                messageIndex.url(routeArgs, { query: { before: cursor } }),
            );

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
    }, [cursor, hasMore, loadingOlder, routeArgs]);

    // -- Mutations ----------------------------------------------------------

    const send = useCallback(
        async (body: string) => {
            if (currentUser === null) {
                return;
            }

            const nonce = crypto.randomUUID();

            setPending((current) => [
                ...current,
                {
                    nonce,
                    body,
                    created_at: new Date().toISOString(),
                    sender: currentUser,
                    failed: false,
                },
            ]);

            try {
                const { message } = await api.post<{ message: Message }>(
                    messageStore.url(routeArgs),
                    { body },
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
        [currentUser, routeArgs, upsert],
    );

    const retry = useCallback(
        (nonce: string) => {
            const failed = pending.find((p) => p.nonce === nonce);

            if (failed === undefined) {
                return;
            }

            setPending((current) => current.filter((p) => p.nonce !== nonce));
            void send(failed.body);
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
        if (newestSeen.current === null || !channel.is_member) {
            return;
        }

        void api
            .post(markRead.url(routeArgs), {
                last_read_message_id: newestSeen.current,
            })
            .catch((error: unknown) => {
                if (!(error instanceof ApiError)) {
                    throw error;
                }
            });
    }, [channel.is_member, routeArgs]);

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
        markAsRead,
    };
}
