import { Head } from '@inertiajs/react';
import { MessagesSquare } from 'lucide-react';
import { EmptyState } from '@/components/snack/empty-state';
import { ThreadRow } from '@/components/snack/thread-row';
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
