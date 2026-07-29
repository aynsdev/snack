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
 */
export function ChannelListItem({
    channel,
    workspaceSlug,
    isActive,
    unreadMessages = 0,
    unreadMentions = 0,
}: {
    channel: Channel;
    workspaceSlug: string;
    isActive: boolean;
    unreadMessages?: number;
    unreadMentions?: number;
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
            className={cn(
                'group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors',
                'hover:bg-sidebar-accent',
                isActive
                    ? 'bg-brand text-primary-foreground hover:bg-brand'
                    : hasUnread
                      ? 'font-semibold text-unread'
                      : 'text-muted-foreground',
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
