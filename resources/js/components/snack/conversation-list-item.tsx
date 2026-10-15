import { Link } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { AvatarGroup, UserAvatar } from '@/components/snack/user-avatar';
import { cn } from '@/lib/utils';
import { show as showConversation } from '@/routes/conversations';
import type { Conversation, SnackUser } from '@/types';

/**
 * A conversation row in the sidebar.
 *
 * Unread state is signalled twice over — the name goes bold *and* gains a count —
 * so it does not rely on weight alone. A DM count is rendered in the mention
 * colour rather than as a quiet dot: everything said in a conversation you are in
 * is addressed to you, so it deserves the loudest treatment the sidebar has.
 */
export function ConversationListItem({
    conversation,
    workspaceSlug,
    viewerId,
    isActive,
    unreadMessages = 0,
}: {
    conversation: Conversation;
    workspaceSlug: string;
    /** Used to drop the viewer from the avatars — you know you are in it. */
    viewerId: number | null;
    isActive: boolean;
    unreadMessages?: number;
}) {
    const hasUnread = unreadMessages > 0 && !conversation.is_muted;

    const others = conversation.participants.filter(
        (participant) => participant.id !== viewerId,
    );

    return (
        <Link
            href={showConversation({
                workspace: workspaceSlug,
                conversation: conversation.id,
            })}
            prefetch="hover"
            aria-current={isActive ? 'page' : undefined}
            className={cn(
                'group flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors',
                'hover:bg-sidebar-accent',
                isActive
                    ? 'bg-foreground/8 font-medium text-foreground hover:bg-foreground/8'
                    : hasUnread
                      ? 'font-semibold text-unread'
                      : 'text-muted-foreground',
            )}
        >
            <ConversationAvatar others={others} />

            <span className="min-w-0 flex-1 truncate">
                {conversation.display_name}
            </span>

            {conversation.is_muted && (
                <BellOff
                    aria-label="Muted"
                    className="size-3.5 shrink-0 opacity-50"
                />
            )}

            {hasUnread && (
                <span className="min-w-5 shrink-0 rounded-full bg-mention px-1.5 py-0.5 text-center text-[0.6875rem] leading-none font-bold text-mention-foreground tabular-nums">
                    {unreadMessages > 99 ? '99+' : unreadMessages}
                    <span className="sr-only"> unread messages</span>
                </span>
            )}
        </Link>
    );
}

/**
 * The face of a conversation: one person with their presence, or a stack for a
 * group.
 *
 * Presence only shows on a one-to-one row. On a group it would be four dots
 * competing for the same corner and answering a question nobody asked — "is this
 * group online?" is not a thing.
 */
function ConversationAvatar({ others }: { others: SnackUser[] }) {
    if (others.length === 0) {
        // Everyone else left the group. Rare, but it must still render.
        return (
            <span
                aria-hidden="true"
                className="size-5 shrink-0 rounded-lg bg-muted"
            />
        );
    }

    if (others.length === 1) {
        return (
            <UserAvatar
                user={others[0]}
                size="xs"
                showPresence
                ringClassName="ring-[--color-sidebar]"
            />
        );
    }

    return <AvatarGroup users={others} max={2} size="xs" />;
}
