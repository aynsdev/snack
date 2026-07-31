import { Head, Link } from '@inertiajs/react';
import { MessagesSquare } from 'lucide-react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { EmptyState } from '@/components/snack/empty-state';
import { MessageText } from '@/components/snack/message-text';
import { UserAvatar } from '@/components/snack/user-avatar';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import type { ThreadSummary } from '@/types';

type Props = {
    threads: ThreadSummary[];
};

/**
 * Every thread the user follows, newest activity first.
 *
 * A digest rather than a feed: each thread appears once, with its root and its
 * reply count, and opening it goes to the container where the panel can render
 * it in context. Listing individual replies here would be dominated by whichever
 * thread happens to be busiest and would answer a question nobody asked —
 * "what needs me?" is a count of conversations, not of messages.
 */
export default function ThreadsIndex({ threads }: Props) {
    return (
        <>
            <Head title="Threads" />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center gap-2 border-b border-border px-4 py-3">
                    <MessagesSquare
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    <h1 className="text-base font-bold tracking-tight">
                        Threads
                    </h1>

                    {threads.length > 0 && (
                        <span className="text-sm text-muted-foreground tabular-nums">
                            {threads.length}
                        </span>
                    )}
                </header>

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    {threads.length === 0 ? (
                        <EmptyState
                            icon={MessagesSquare}
                            title="No threads yet"
                            description="Reply to a message to start a thread. Anything you start or answer shows up here, so you can pick it back up without hunting for it."
                        />
                    ) : (
                        <ul className="divide-y divide-border">
                            {threads.map((thread) => (
                                <ThreadRow
                                    key={thread.root.id}
                                    thread={thread}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

function ThreadRow({ thread }: { thread: ThreadSummary }) {
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
