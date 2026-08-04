import { Form, Head, Link } from '@inertiajs/react';
import { AtSign, Bell, CheckCheck, MessageSquare, Reply } from 'lucide-react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { EmptyState } from '@/components/snack/empty-state';
import { Button } from '@/components/ui/button';
import { useNotifications } from '@/hooks/use-notifications';
import { useCurrentUser, useWorkspace } from '@/hooks/use-workspace';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import { update as markAllRead } from '@/routes/notifications/read';
import type { NotificationEntry, NotificationReason } from '@/types';

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

function NotificationRow({
    notification,
    onOpen,
}: {
    notification: NotificationEntry;
    onOpen: (notification: NotificationEntry) => void;
}) {
    const href = useContainerHref(notification.container);
    const actor = notification.actor;

    /*
     * Falls back rather than indexing straight in. This payload arrives over a
     * WebSocket as well as from the server, and an unrecognised reason resolving
     * to `undefined` here is not a missing icon — it is `<undefined />`, which
     * throws and blanks the whole page.
     */
    const Icon = REASON_ICONS[notification.type] ?? Bell;

    return (
        <li>
            <Link
                href={href ?? '#'}
                // Stamped read on the way out rather than on arrival: a
                // notification is read when the reader goes to look at what it
                // was about, not when it scrolls past.
                onClick={() => onOpen(notification)}
                className={cn(
                    'flex gap-3 px-4 py-3 transition-colors hover:bg-accent/50',
                    href === null && 'pointer-events-none opacity-60',
                    !notification.is_read && 'bg-brand-subtle/30',
                )}
            >
                <div className="pt-0.5">
                    <span className="flex size-8 items-center justify-center rounded-full bg-muted">
                        <Icon
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                    </span>
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <ContainerLabel container={notification.container} />

                        {!notification.is_read && (
                            <span
                                aria-label="Unread"
                                className="size-2 shrink-0 rounded-full bg-mention"
                            />
                        )}

                        {notification.created_at !== null && (
                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                {dayLabel(notification.created_at)}
                            </span>
                        )}
                    </div>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {headline(
                            notification.type,
                            actor?.name ?? 'Someone',
                            notification.count,
                        )}
                    </p>

                    {/*
                     * A snapshot of what was said at the time, not a live read
                     * of the message — which is what lets this render at all
                     * once the notification outlives its context.
                     */}
                    <p className="mt-1 line-clamp-2 text-sm">
                        {notification.preview}
                    </p>
                </div>
            </Link>
        </li>
    );
}

const REASON_ICONS: Record<NotificationReason, typeof AtSign> = {
    mention: AtSign,
    direct_message: MessageSquare,
    thread_reply: Reply,
};

/**
 * Mirrors App\Enums\NotificationReason::headline(), plus the count.
 *
 * A row standing for several messages says so here rather than in a badge: the
 * headline is the line that explains what happened, and "sent you 4 messages"
 * is the whole explanation. The preview below it is the last of them.
 */
function headline(
    reason: NotificationReason,
    actorName: string,
    count: number,
): string {
    switch (reason) {
        case 'mention':
            return `${actorName} mentioned you`;
        case 'direct_message':
            return count > 1
                ? `${actorName} sent you ${count} messages`
                : `${actorName} sent you a message`;
        case 'thread_reply':
            return `${actorName} replied in a thread`;
        default:
            // Same reasoning as the icon fallback: a reason this build does not
            // know about should read plainly, not crash the list.
            return `${actorName} sent you something`;
    }
}
