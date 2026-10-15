import { Link } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { ChannelIcon } from '@/components/snack/channel-icon';
import { cn } from '@/lib/utils';
import { show as showChannel } from '@/routes/channels';
import type { Channel } from '@/types';

/**
 * A channel row in the sidebar.
 *
 * Unread state is signalled twice over: the name goes bold *and* gains a dot,
 * so it does not rely on weight alone. A mention count is louder still, since a
 * direct mention is the one thing a person must not miss.
 *
 * # Dragging
 *
 * Native HTML5 drag and drop, as the board is — see TaskCard for why no library
 * is involved. The row is a link, which browsers already drag as a URL, so
 * `onDragStart` overwrites the payload with the channel id and the sections
 * read that. Only offered when there is a section to drop into; the row's ⋯
 * menu does the same job from the keyboard either way.
 */
export function ChannelListItem({
    channel,
    workspaceSlug,
    isActive,
    unreadMessages = 0,
    unreadMentions = 0,
    draggable = false,
    isDragging = false,
    onDragStart,
    onDragEnd,
}: {
    channel: Channel;
    workspaceSlug: string;
    isActive: boolean;
    unreadMessages?: number;
    unreadMentions?: number;
    draggable?: boolean;
    /** Dimmed in place while it is the row being dragged. */
    isDragging?: boolean;
    onDragStart?: (channel: Channel) => void;
    onDragEnd?: () => void;
}) {
    const hasUnread = unreadMessages > 0 && !channel.is_muted;

    return (
        <Link
            href={showChannel({
                workspace: workspaceSlug,
                channel: channel.slug,
            })}
            prefetch="hover"
            aria-current={isActive ? 'page' : undefined}
            draggable={draggable}
            onDragStart={(event) => {
                if (!draggable) {
                    return;
                }

                // The id travels in the payload as well as in React state, for
                // the reason TaskCard's does: a drop handler reading only from
                // state reads whatever was last set.
                event.dataTransfer.setData('text/plain', String(channel.id));
                event.dataTransfer.effectAllowed = 'move';
                onDragStart?.(channel);
            }}
            onDragEnd={onDragEnd}
            className={cn(
                'group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors',
                'hover:bg-sidebar-accent',
                isActive
                    ? 'bg-foreground/8 font-medium text-foreground hover:bg-foreground/8'
                    : hasUnread
                      ? 'font-semibold text-unread'
                      : 'text-muted-foreground',
                draggable && 'cursor-grab active:cursor-grabbing',
                isDragging && 'opacity-40',
            )}
        >
            <ChannelIcon
                type={channel.type}
                className={cn(
                    isActive ? 'opacity-90' : 'opacity-60',
                    'group-hover:opacity-100',
                )}
            />

            <span className="min-w-0 flex-1 truncate">{channel.name}</span>

            {channel.is_muted && (
                <BellOff
                    aria-label="Muted"
                    className="size-3.5 shrink-0 opacity-50"
                />
            )}

            {unreadMentions > 0 ? (
                <span className="min-w-5 shrink-0 rounded-full bg-mention px-1.5 py-0.5 text-center text-[0.6875rem] leading-none font-bold text-mention-foreground tabular-nums">
                    {unreadMentions > 99 ? '99+' : unreadMentions}
                    <span className="sr-only"> unread mentions</span>
                </span>
            ) : (
                hasUnread &&
                !isActive && (
                    <span
                        className="size-2 shrink-0 rounded-full bg-brand"
                        aria-label={`${unreadMessages} unread messages`}
                    />
                )
            )}
        </Link>
    );
}
