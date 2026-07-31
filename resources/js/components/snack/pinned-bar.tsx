import { ChevronDown, ChevronUp, Pin } from 'lucide-react';
import { useState } from 'react';
import { MessageText } from '@/components/snack/message-text';
import { cn } from '@/lib/utils';
import type { Message } from '@/types';

/**
 * The pinned messages at the top of a channel or conversation.
 *
 * # Why it collapses
 *
 * A pinned message is reference material — a runbook link, the standup time —
 * so it has to be reachable without scrolling, and it must not eat the room the
 * conversation needs. Collapsed it is one line naming the count and previewing
 * the most recent pin; expanded it lists them all.
 *
 * It renders nothing at all when there is nothing pinned, rather than an empty
 * bar explaining that pinning exists.
 */
export function PinnedBar({
    pinned,
    onOpen,
    onUnpin,
}: {
    pinned: Message[];
    /** Jumps the timeline to the pinned message. */
    onOpen?: (message: Message) => void;
    /** Omitted where the viewer lacks pinning rights. */
    onUnpin?: (message: Message) => void;
}) {
    const [expanded, setExpanded] = useState(false);

    if (pinned.length === 0) {
        return null;
    }

    const newest = pinned[0];

    return (
        <div className="border-b border-border bg-brand-subtle/40">
            <button
                type="button"
                onClick={() => setExpanded((open) => !open)}
                aria-expanded={expanded}
                className="flex w-full items-center gap-2 px-4 py-2 text-left transition-colors hover:bg-brand-subtle/60"
            >
                <Pin
                    aria-hidden="true"
                    className="size-3.5 shrink-0 text-brand"
                />

                <span className="shrink-0 text-xs font-semibold text-brand">
                    {pinned.length === 1
                        ? '1 pinned'
                        : `${pinned.length} pinned`}
                </span>

                {!expanded && (
                    <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                        {newest.sender?.name ?? 'Snack'}: {preview(newest.body)}
                    </span>
                )}

                <span className="ml-auto shrink-0 text-muted-foreground">
                    {expanded ? (
                        <ChevronUp aria-hidden="true" className="size-3.5" />
                    ) : (
                        <ChevronDown aria-hidden="true" className="size-3.5" />
                    )}
                </span>
            </button>

            {expanded && (
                <ul className="max-h-64 overflow-y-auto border-t border-border/60">
                    {pinned.map((message) => (
                        <li
                            key={message.id}
                            className="flex items-start gap-2 px-4 py-2 hover:bg-brand-subtle/40"
                        >
                            <div
                                className={cn(
                                    'min-w-0 flex-1',
                                    onOpen !== undefined && 'cursor-pointer',
                                )}
                                onClick={() => onOpen?.(message)}
                            >
                                <p className="text-xs font-semibold">
                                    {message.sender?.name ?? 'Snack'}
                                </p>

                                <div className="line-clamp-2 text-xs text-muted-foreground">
                                    <MessageText body={message.body} />
                                </div>
                            </div>

                            {onUnpin !== undefined && (
                                <button
                                    type="button"
                                    onClick={() => onUnpin(message)}
                                    className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                >
                                    <Pin
                                        aria-hidden="true"
                                        className="size-3"
                                    />
                                    <span className="sr-only">
                                        Unpin this message
                                    </span>
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * A one-line preview for the collapsed bar.
 *
 * Deliberately strips nothing but whitespace: the bar is one line and clipped by
 * CSS, so a body with formatting markers in it reads as what the author typed
 * rather than as a half-rendered fragment.
 */
function preview(body: string): string {
    return body.replace(/\s+/g, ' ').trim();
}
