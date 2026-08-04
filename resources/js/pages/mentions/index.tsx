import { Head } from '@inertiajs/react';
import { AtSign } from 'lucide-react';
import { EmptyState } from '@/components/snack/empty-state';
import { MentionRow } from '@/components/snack/mention-row';
import type { MentionSummary } from '@/types';

type Props = {
    mentions: MentionSummary[];
};

/**
 * Everything addressed to you in this workspace.
 *
 * A history, not an inbox: rows stay after they are read, so this still answers
 * "what was I asked about last week" once the badge has cleared. Read and unread
 * are distinguished by weight rather than by being separate lists — the unread
 * ones are why you opened the page, and the rest are why you keep it.
 */
export default function MentionsIndex({ mentions }: Props) {
    const unread = mentions.filter((mention) => !mention.is_read).length;

    return (
        <>
            <Head title="Mentions" />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center gap-2 border-b border-border px-4 py-3">
                    <AtSign
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    <h1 className="text-base font-bold tracking-tight">
                        Mentions
                    </h1>

                    {unread > 0 && (
                        <span className="rounded-full bg-mention px-1.5 py-0.5 text-[0.6875rem] leading-none font-bold text-mention-foreground tabular-nums">
                            {unread}
                        </span>
                    )}
                </header>

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    {mentions.length === 0 ? (
                        <EmptyState
                            icon={AtSign}
                            title="No mentions yet"
                            description="When somebody types your @handle — or addresses the whole channel — it lands here, so you can find it again without scrolling back through every conversation."
                        />
                    ) : (
                        <ul className="divide-y divide-border">
                            {mentions.map((mention) => (
                                <MentionRow
                                    key={mention.id}
                                    mention={mention}
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
