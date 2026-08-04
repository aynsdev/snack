import { Form, Head } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { EmptyState } from '@/components/snack/empty-state';
import { NotificationRow } from '@/components/snack/notification-row';
import { Button } from '@/components/ui/button';
import { useNotifications } from '@/hooks/use-notifications';
import { useCurrentUser, useWorkspace } from '@/hooks/use-workspace';
import { update as markAllRead } from '@/routes/notifications/read';
import type { NotificationEntry } from '@/types';

type Props = {
    notifications: NotificationEntry[];
};

/**
 * Everything Snack has raised to you in this workspace.
 *
 * A history rather than a queue, like Mentions: rows stay after they are read,
 * so this still answers "what came in while I was away" once the badge has
 * cleared. Read and unread differ by weight rather than by being separate lists
 * — the unread ones are why you opened the page, and the rest are why you keep
 * it.
 *
 * Distinct from Mentions on purpose. A mention is a thing said in a room you are
 * in; this is a record of Snack raising something to you, which is also how a
 * direct message and a followed thread reach you, and neither of those is a
 * mention.
 */
export default function NotificationsIndex({ notifications: initial }: Props) {
    const workspace = useWorkspace();
    const currentUser = useCurrentUser();

    const { notifications, markAsRead } = useNotifications({
        workspaceSlug: workspace.slug,
        userId: currentUser?.id ?? 0,
        initial,
    });

    const unread = notifications.filter((entry) => !entry.is_read).length;

    return (
        <>
            <Head title="Notifications" />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center gap-2 border-b border-border px-4 py-3">
                    <Bell
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    <h1 className="text-base font-bold tracking-tight">
                        Notifications
                    </h1>

                    {unread > 0 && (
                        <span className="rounded-full bg-mention px-1.5 py-0.5 text-[0.6875rem] leading-none font-bold text-mention-foreground tabular-nums">
                            {unread}
                        </span>
                    )}

                    {unread > 0 && (
                        <Form
                            {...markAllRead.form(workspace.slug)}
                            className="ml-auto"
                        >
                            <Button
                                type="submit"
                                variant="ghost"
                                size="sm"
                                className="gap-1.5 text-muted-foreground"
                            >
                                <CheckCheck aria-hidden="true" />
                                Mark all read
                            </Button>
                        </Form>
                    )}
                </header>

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    {notifications.length === 0 ? (
                        <EmptyState
                            icon={Bell}
                            title="Nothing waiting"
                            description="Mentions, direct messages, and replies in threads you follow land here — so you can catch up on what was aimed at you without rereading every channel."
                        />
                    ) : (
                        <ul className="divide-y divide-border">
                            {notifications.map((notification) => (
                                <NotificationRow
                                    key={notification.id}
                                    notification={notification}
                                    onOpen={markAsRead}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
