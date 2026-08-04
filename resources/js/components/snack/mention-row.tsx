import { Link } from '@inertiajs/react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { MessageText } from '@/components/snack/message-text';
import { UserAvatar } from '@/components/snack/user-avatar';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import type { MentionSummary } from '@/types';

/**
 * One message that named you.
 *
 * Shared by the Mentions page and the Activity page's Mentions and All tabs, so
 * the same event reads the same wherever it surfaces.
 */
export function MentionRow({ mention }: { mention: MentionSummary }) {
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
