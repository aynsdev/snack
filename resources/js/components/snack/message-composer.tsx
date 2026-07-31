import { SendHorizontal } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import {
    MentionPicker,
    useMentionCandidates,
} from '@/components/snack/mention-picker';
import type { MentionSource } from '@/components/snack/mention-picker';
import { TypingIndicator } from '@/components/snack/typing-indicator';
import { Textarea } from '@/components/ui/textarea';
import type { Typist } from '@/hooks/use-typing';
import { applyMention, mentionQueryAt } from '@/lib/mention-query';
import type { MentionQuery } from '@/lib/mention-query';
import { cn } from '@/lib/utils';
import type { SnackUser } from '@/types';

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
 *
 * # The @-picker
 *
 * While a mention is being typed, Enter and the arrow keys belong to the picker
 * rather than to the composer: with a list open, Enter means "choose this
 * person", and sending the message half-written instead is the mistake that
 * makes people stop using the picker.
 */
export function MessageComposer({
    channelName,
    disabled = false,
    disabledReason,
    mentionSource,
    typists = [],
    onTyping,
    onStopTyping,
    onSend,
}: {
    channelName: string;
    disabled?: boolean;
    disabledReason?: string;
    /** Omitted where there is nobody to offer, e.g. a thread in a preview. */
    mentionSource?: MentionSource;
    /** Who else is typing here, rendered just above the box. */
    typists?: Typist[];
    /** Called per keystroke; the hook throttles, so this need not. */
    onTyping?: () => void;
    onStopTyping?: () => void;
    onSend: (body: string) => void;
}) {
    const [body, setBody] = useState('');
    const [mention, setMention] = useState<MentionQuery | null>(null);
    const [activeIndex, setActiveIndex] = useState(0);
    const textarea = useRef<HTMLTextAreaElement>(null);

    const candidates = useMentionCandidates(
        mention?.term ?? null,
        mentionSource,
    );

    const trimmed = body.trim();
    const canSend = trimmed !== '' && trimmed.length <= MAX_LENGTH && !disabled;
    const picking = mention !== null && candidates.length > 0;

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

    /** Re-read where the caret is, and whether it sits inside a handle. */
    const syncMention = useCallback(
        (value: string, caret: number | null) => {
            if (mentionSource === undefined || caret === null) {
                setMention(null);

                return;
            }

            setMention(mentionQueryAt(value, caret));
            setActiveIndex(0);
        },
        [mentionSource],
    );

    const choose = (person: SnackUser) => {
        if (mention === null) {
            return;
        }

        const next = applyMention(body, mention, person.username);

        setBody(next.value);
        setMention(null);

        // The caret has to be restored after React commits the new value, or the
        // browser parks it at the end of the text.
        requestAnimationFrame(() => {
            const element = textarea.current;

            if (element !== null) {
                element.focus();
                element.setSelectionRange(next.caret, next.caret);
                resize();
            }
        });
    };

    const send = () => {
        if (!canSend) {
            return;
        }

        onSend(trimmed);
        setBody('');
        setMention(null);

        // The message has landed, so the indicator should go now rather than
        // waiting out its expiry on everyone else's screen.
        onStopTyping?.();

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
            <TypingIndicator typists={typists} className="mb-1" />

            <div className="relative">
                <MentionPicker
                    people={candidates}
                    activeIndex={activeIndex}
                    onChoose={choose}
                />

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
                        aria-autocomplete={
                            mentionSource === undefined ? undefined : 'list'
                        }
                        aria-expanded={picking}
                        onChange={(event) => {
                            setBody(event.target.value);
                            syncMention(
                                event.target.value,
                                event.target.selectionStart,
                            );
                            resize();

                            /*
                             * Announced on content change rather than on keydown,
                             * so arrow keys and modifiers do not claim someone is
                             * typing when they are only moving the caret.
                             */
                            if (event.target.value === '') {
                                onStopTyping?.();
                            } else {
                                onTyping?.();
                            }
                        }}
                        onClick={(event) =>
                            syncMention(
                                body,
                                event.currentTarget.selectionStart,
                            )
                        }
                        onBlur={() => {
                            setMention(null);
                            onStopTyping?.();
                        }}
                        onKeyDown={(event) => {
                            /*
                             * With a list open these keys belong to it. Checked
                             * before the send handler, so Enter never sends a
                             * half-typed handle.
                             */
                            if (picking) {
                                if (event.key === 'ArrowDown') {
                                    event.preventDefault();
                                    setActiveIndex(
                                        (index) =>
                                            (index + 1) % candidates.length,
                                    );

                                    return;
                                }

                                if (event.key === 'ArrowUp') {
                                    event.preventDefault();
                                    setActiveIndex(
                                        (index) =>
                                            (index - 1 + candidates.length) %
                                            candidates.length,
                                    );

                                    return;
                                }

                                if (
                                    event.key === 'Enter' ||
                                    event.key === 'Tab'
                                ) {
                                    event.preventDefault();
                                    choose(candidates[activeIndex]);

                                    return;
                                }

                                if (event.key === 'Escape') {
                                    event.preventDefault();
                                    setMention(null);

                                    return;
                                }
                            }

                            if (event.key === 'Enter' && !event.shiftKey) {
                                event.preventDefault();
                                send();
                            }
                        }}
                        onKeyUp={(event) => {
                            // Arrow keys and clicks move the caret without
                            // changing the text, so the query is re-read here too.
                            if (event.key.startsWith('Arrow') && !picking) {
                                syncMention(
                                    body,
                                    event.currentTarget.selectionStart,
                                );
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
                            <SendHorizontal
                                aria-hidden="true"
                                className="size-4"
                            />
                            <span className="sr-only">Send message</span>
                        </button>
                    </div>
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
