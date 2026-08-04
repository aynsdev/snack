import { Link } from '@inertiajs/react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { MessageText } from '@/components/snack/message-text';
import { UserAvatar } from '@/components/snack/user-avatar';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import type { ThreadSummary } from '@/types';

/**
 * One thread you follow, as a digest: its root, and how many replies it has.
 *
 * Shared by the Threads page and the Activity page's Threads and All tabs.
 * Listing individual replies instead would let the busiest thread dominate a
 * list that is meant to answer "what needs me?" — which is a count of
 * conversations, not of messages.
 */
export function ThreadRow({ thread }: { thread: ThreadSummary }) {
    const container = thread.container;
    const href = useContainerHref(container);

    return (
        <li>
            <Link
                href={href ?? '#'}
                className={cn(
                    'flex gap-3 px-4 py-3 transition-colors hover:bg-accent/50',
                    href === null && 'pointer-events-none opacity-60',
                )}
            >
                <div className="pt-0.5">
                    {thread.root.sender && (
                        <UserAvatar user={thread.root.sender} size="md" />
                    )}
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <ContainerLabel container={container} />

                        {thread.is_unread && (
                            <span
                                aria-label="Unread replies"
                                className="size-2 shrink-0 rounded-full bg-mention"
                            />
                        )}

                        {thread.root.last_reply_at !== null && (
                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                {dayLabel(thread.root.last_reply_at)}
                            </span>
                        )}
                    </div>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {thread.root.sender?.name ?? 'Snack'}
                    </p>

                    <div className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                        <MessageText body={thread.root.body} />
                    </div>

                    <p
                        className={cn(
                            'mt-1.5 text-xs font-semibold',
                            thread.is_unread
                                ? 'text-brand'
                                : 'text-muted-foreground',
                        )}
                    >
                        {thread.root.reply_count === 1
                            ? '1 reply'
                            : `${thread.root.reply_count} replies`}
                    </p>
                </div>
            </Link>
        </li>
    );
}
