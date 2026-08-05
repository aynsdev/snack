import { Link } from '@inertiajs/react';
import { AtSign, Bell, KanbanSquare, MessageSquare, Reply } from 'lucide-react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { useWorkspace } from '@/hooks/use-workspace';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import { board as boardRoute } from '@/routes/projects';
import type { NotificationEntry, NotificationReason } from '@/types';

/**
 * One thing Snack raised to you.
 *
 * Shared by the notification centre and the Activity page, which renders it on
 * its Notifications tab and interleaved with mentions and threads on All. A row
 * that looked different depending on which list it turned up in would read as
 * two different kinds of event.
 */
export function NotificationRow({
    notification,
    onOpen,
}: {
    notification: NotificationEntry;
    onOpen: (notification: NotificationEntry) => void;
}) {
    const workspace = useWorkspace();
    const containerHref = useContainerHref(notification.container);
    const actor = notification.actor;

    /*
     * An assignment has no container to link through — it is not about a message
     * — so it builds its own destination: the board, with the card named in the
     * query so it opens on arrival. See Projects\BoardController::openTask().
     */
    const href =
        notification.task === null
            ? containerHref
            : boardRoute.url(
                  {
                      workspace: workspace.slug,
                      project: notification.task.project_slug,
                  },
                  { query: { task: notification.task.reference } },
              );

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
                        {/*
                         * Where it came from. An assignment came from a board
                         * rather than a room, and ContainerLabel has nothing to
                         * name for it — it would read "Unknown", which is the
                         * one thing this line must never say about a row the
                         * server knows exactly where to send.
                         */}
                        {notification.task === null ? (
                            <ContainerLabel
                                container={notification.container}
                            />
                        ) : (
                            <span className="flex min-w-0 items-center gap-1.5 text-sm font-medium">
                                <KanbanSquare
                                    aria-hidden="true"
                                    className="size-3.5 shrink-0 text-muted-foreground"
                                />
                                <span className="truncate">
                                    {notification.task.project_name}
                                </span>
                            </span>
                        )}

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
    task_assigned: KanbanSquare,
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
        case 'task_assigned':
            return `${actorName} assigned you a task`;
        default:
            // Same reasoning as the icon fallback: a reason this build does not
            // know about should read plainly, not crash the list.
            return `${actorName} sent you something`;
    }
}
