import { SendHorizontal } from 'lucide-react';
import { useRef, useState } from 'react';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

/** Matches config('snack.messages.max_length'); the server is the authority. */
const MAX_LENGTH = 12000;

/** Show the counter only once the limit is actually in sight. */
const COUNTER_THRESHOLD = MAX_LENGTH - 500;

/**
 * The message composer.
 *
 * Enter sends and Shift+Enter inserts a newline. That is the convention people
 * arrive with, and getting it backwards means every multi-line message is sent
 * half-finished.
 *
 * The textarea grows with its content up to a ceiling, after which it scrolls —
 * without the ceiling, pasting a long snippet would push the conversation off
 * the screen entirely.
 */
export function MessageComposer({
    channelName,
    disabled = false,
    disabledReason,
    onSend,
}: {
    channelName: string;
    disabled?: boolean;
    disabledReason?: string;
    onSend: (body: string) => void;
}) {
    const [body, setBody] = useState('');
    const textarea = useRef<HTMLTextAreaElement>(null);

    const trimmed = body.trim();
    const canSend = trimmed !== '' && trimmed.length <= MAX_LENGTH && !disabled;

    const resize = () => {
        const element = textarea.current;

        if (element === null) {
            return;
        }

        // Reset before measuring: scrollHeight only ever grows, so without this
        // the box can expand but never shrink back.
        element.style.height = 'auto';
        element.style.height = `${Math.min(element.scrollHeight, 220)}px`;
    };

    const send = () => {
        if (!canSend) {
            return;
        }

        onSend(trimmed);
        setBody('');

        // Height is inline style, so clearing the value alone leaves the box
        // stretched to the size of the message that was just sent.
        requestAnimationFrame(() => {
            if (textarea.current !== null) {
                textarea.current.style.height = 'auto';
            }
        });
    };

    if (disabled && disabledReason !== undefined) {
        return (
            <div className="border-t border-border p-4">
                <p className="rounded-xl border border-border bg-muted/40 px-3.5 py-3 text-sm text-muted-foreground">
                    {disabledReason}
                </p>
            </div>
        );
    }

    return (
        <div className="border-t border-border p-4">
            <div
                className={cn(
                    'flex items-end gap-2 rounded-xl border border-input bg-background px-3 py-2',
                    'focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/25',
                )}
            >
                <Textarea
                    ref={textarea}
                    value={body}
                    rows={1}
                    aria-label={`Message ${channelName}`}
                    placeholder={`Message ${channelName}`}
                    onChange={(event) => {
                        setBody(event.target.value);
                        resize();
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            send();
                        }
                    }}
                    className={cn(
                        'min-h-0 resize-none border-0 bg-transparent p-0 shadow-none',
                        'focus-visible:ring-0 focus-visible:ring-offset-0',
                    )}
                />

                <div className="flex shrink-0 items-center gap-2 pb-0.5">
                    {trimmed.length > COUNTER_THRESHOLD && (
                        <span
                            className={cn(
                                'text-xs tabular-nums',
                                trimmed.length > MAX_LENGTH
                                    ? 'font-semibold text-danger'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {MAX_LENGTH - trimmed.length}
                        </span>
                    )}

                    <button
                        type="button"
                        onClick={send}
                        disabled={!canSend}
                        className={cn(
                            'rounded-lg p-1.5 transition-colors',
                            canSend
                                ? 'bg-brand text-primary-foreground hover:bg-brand-hover'
                                : 'text-muted-foreground/50',
                        )}
                    >
                        <SendHorizontal aria-hidden="true" className="size-4" />
                        <span className="sr-only">Send message</span>
                    </button>
                </div>
            </div>

            <p className="mt-1.5 px-1 text-[0.6875rem] text-muted-foreground">
                <strong className="font-semibold">Enter</strong> to send,{' '}
                <strong className="font-semibold">Shift + Enter</strong> for a
                new line
            </p>
        </div>
    );
}
