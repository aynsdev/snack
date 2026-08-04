import { Link } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { UserAvatar } from '@/components/snack/user-avatar';
import { cn } from '@/lib/utils';
import {
    show as showConversation,
    store as startConversation,
} from '@/routes/conversations';
import type { WorkspacePerson } from '@/types';

/**
 * A person in the sidebar's direct message list.
 *
 * The list is the workspace roster, not a list of threads, so most rows here
 * have no conversation behind them yet. Those render as a POST to the
 * find-or-create endpoint instead of a link: the thread is opened by the click
 * that needs it, which is the whole point of listing people rather than
 * requiring a conversation per member to exist first.
 *
 * Both shapes are deliberately the same row. Whether you have messaged someone
 * before is not a distinction worth drawing in a list you are scanning for a
 * name, and a person who arrives with unread messages must look identical to a
 * conversation that does.
 */
export function PersonListItem({
    person,
    workspaceSlug,
    isActive,
    unreadMessages = 0,
}: {
    person: WorkspacePerson;
    workspaceSlug: string;
    isActive: boolean;
    unreadMessages?: number;
}) {
    const { user, conversation } = person;
    const isMuted = conversation?.is_muted ?? false;
    const hasUnread = unreadMessages > 0 && !isMuted;

    const className = cn(
        'group flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors',
        'hover:bg-sidebar-accent',
        isActive
            ? 'bg-brand text-primary-foreground hover:bg-brand'
            : hasUnread
              ? 'font-semibold text-unread'
              : 'text-muted-foreground',
    );

    const content = (
        <>
            <UserAvatar
                user={user}
                size="xs"
                showPresence
                ringClassName="ring-[--color-sidebar]"
            />

            <span className="min-w-0 flex-1 truncate">{user.name}</span>

            {isMuted && (
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
        </>
    );

    if (conversation === null) {
        return (
            <Link
                href={startConversation(workspaceSlug)}
                data={{ participant_ids: [user.id] }}
                as="button"
                type="button"
                /*
                 * The server redirects into the conversation, so the sidebar
                 * unmounts and remounts around this row; keeping the scroll
                 * position is what stops the list jumping back to the top of a
                 * roster the reader had scrolled halfway down.
                 */
                preserveScroll
                className={className}
            >
                {content}
            </Link>
        );
    }

    return (
        <Link
            href={showConversation({
                workspace: workspaceSlug,
                conversation: conversation.id,
            })}
            prefetch="hover"
            aria-current={isActive ? 'page' : undefined}
            className={className}
        >
            {content}
        </Link>
    );
}
