import { Head, Link } from '@inertiajs/react';
import { AtSign } from 'lucide-react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { EmptyState } from '@/components/snack/empty-state';
import { MessageText } from '@/components/snack/message-text';
import { UserAvatar } from '@/components/snack/user-avatar';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
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

function MentionRow({ mention }: { mention: MentionSummary }) {
    const href = useContainerHref(mention.container);
    const message = mention.message;

    return (
        <li>
            <Link
                href={href ?? '#'}
                className={cn(
                    'flex gap-3 px-4 py-3 transition-colors hover:bg-accent/50',
                    href === null && 'pointer-events-none opacity-60',
                    !mention.is_read && 'bg-brand-subtle/30',
                )}
            >
                <div className="pt-0.5">
                    {message.sender && (
                        <UserAvatar user={message.sender} size="md" />
                    )}
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <ContainerLabel container={mention.container} />

                        {/*
                         * A broadcast mention is worth distinguishing: "@everyone"
                         * landing here is a different kind of claim on your
                         * attention from someone typing your name.
                         */}
                        {mention.type !== 'user' && (
                            <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[0.625rem] font-semibold text-muted-foreground">
                                @{mention.type}
                            </span>
                        )}

                        {!mention.is_read && (
                            <span
                                aria-label="Unread"
                                className="size-2 shrink-0 rounded-full bg-mention"
                            />
                        )}

                        {message.created_at !== null && (
                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                {dayLabel(message.created_at)}
                            </span>
                        )}
                    </div>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {message.sender?.name ?? 'Snack'}
                    </p>

                    <div className="mt-1 line-clamp-3 text-sm">
                        <MessageText body={message.body} />
                    </div>
                </div>
            </Link>
        </li>
    );
}
