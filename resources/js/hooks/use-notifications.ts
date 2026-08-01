import { router } from '@inertiajs/react';
import { useEchoNotification } from '@laravel/echo-react';
import { useCallback, useState } from 'react';
import { api } from '@/lib/api';
/*
 * Configures the transport as a side effect. Imported here rather than left to
 * the entry file: subscribing without it produces no error, just a socket that
 * never connects, so every module that subscribes states the dependency itself.
 */
import '@/lib/echo';
import { store as markRead } from '@/routes/notifications/read';
import type { NotificationEntry } from '@/types';

/**
 * The notification centre's list, kept current while the page is open.
 *
 * # Why the arriving payload is used as-is
 *
 * `MessageNotification::toBroadcast()` sends the same shape
 * `NotificationResource` renders, so an arrival is prepended directly rather
 * than triggering a refetch. A reload per notification would be a request per
 * message in a busy workspace, and the list would still be a frame behind.
 *
 * # Why the badge is not updated here
 *
 * The unread count lives in the shared `snack` prop, which Inertia re-sends on
 * every navigation. Keeping a second copy in sync here would give the app two
 * answers to one question. Marking a row read therefore reloads just that prop
 * — one small request, and the sidebar and this list cannot disagree.
 */
export function useNotifications({
    workspaceSlug,
    userId,
    initial,
}: {
    workspaceSlug: string;
    userId: number;
    initial: NotificationEntry[];
}) {
    const [notifications, setNotifications] =
        useState<NotificationEntry[]>(initial);

    /*
     * Re-seed from the server when a new page response arrives.
     *
     * `useState(initial)` only reads its argument once, so without this the list
     * is frozen at whatever the first render saw. "Mark all read" redirects
     * back, the server returns every row stamped read — and the list carries on
     * rendering the stale copy it captured, so the badge clears while the rows
     * stay bold. That is exactly how this bug was found.
     *
     * React's documented way to adjust state when a prop changes: compare during
     * render rather than in an effect, so the reset lands in the same commit
     * instead of painting the stale list first.
     *
     * The comparison is by identity, which is what makes it fire on a full page
     * response and *not* on the `only: ['snack']` reloads below — a partial
     * reload leaves this prop's identity untouched, so a live arrival is not
     * thrown away by the badge refresh that follows it.
     */
    const [serverCopy, setServerCopy] = useState<NotificationEntry[]>(initial);

    if (serverCopy !== initial) {
        setServerCopy(initial);
        setNotifications(initial);
    }

    /*
     * Laravel broadcasts notifications on `App.Models.User.{id}`, which
     * routes/channels.php already authorizes to the user themselves. The hook
     * subscribes to that convention by id rather than to a channel name built
     * here, so there is no string to get wrong.
     */
    useEchoNotification<NotificationEntry>(
        `App.Models.User.${userId}`,
        (payload) => {
            /*
             * A user can hold one socket while looking at two workspaces in two
             * tabs. The payload names its own workspace, but this list is
             * already scoped to one — so anything for a different container set
             * is dropped rather than rendered under the wrong heading.
             */
            setNotifications((current) =>
                current.some((entry) => entry.id === payload.id)
                    ? current
                    : [payload, ...current],
            );

            // The badge is server-owned; ask for the fresh count rather than
            // incrementing a local copy that could drift.
            router.reload({ only: ['snack'] });
        },
        // The third argument is the event name, which this hook already knows —
        // Laravel broadcasts every notification as BroadcastNotificationCreated.
        // Passing the dependency list in its place subscribes once and never
        // re-subscribes, which looks like the socket silently dying.
        undefined,
        [userId],
    );

    /**
     * Stamp one row read.
     *
     * Optimistic, because there is no shared state to get wrong — read state is
     * private to this person — and a row that stays bold until a round trip
     * finishes reads as a failed click.
     */
    const markAsRead = useCallback(
        async (notification: NotificationEntry) => {
            if (notification.is_read) {
                return;
            }

            setNotifications((current) =>
                current.map((entry) =>
                    entry.id === notification.id
                        ? { ...entry, is_read: true }
                        : entry,
                ),
            );

            try {
                await api.post(
                    markRead.url({
                        workspace: workspaceSlug,
                        notification: notification.id,
                    }),
                );

                router.reload({ only: ['snack'] });
            } catch (cause) {
                setNotifications((current) =>
                    current.map((entry) =>
                        entry.id === notification.id
                            ? { ...entry, is_read: false }
                            : entry,
                    ),
                );

                throw cause;
            }
        },
        [workspaceSlug],
    );

    return { notifications, markAsRead };
}
