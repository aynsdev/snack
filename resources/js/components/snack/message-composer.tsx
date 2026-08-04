import {
    AtSign,
    CaseSensitive,
    Plus,
    SendHorizontal,
    Smile,
} from 'lucide-react';
import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { AttachmentTray } from '@/components/snack/attachment-tray';
import { FormattingToolbar } from '@/components/snack/formatting-toolbar';
import {
    MentionPicker,
    useMentionCandidates,
} from '@/components/snack/mention-picker';
import type { MentionSource } from '@/components/snack/mention-picker';
import { TypingIndicator } from '@/components/snack/typing-indicator';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import type { Typist } from '@/hooks/use-typing';
import { useUploads } from '@/hooks/use-uploads';
import { applyCommand, insertMention, insertText } from '@/lib/composer-format';
import type { Edit, FormatCommand, Selection } from '@/lib/composer-format';
import { REACTION_SHORTCODES, emojiGlyph } from '@/lib/emoji';
import { applyMention, mentionQueryAt } from '@/lib/mention-query';
import type { MentionQuery } from '@/lib/mention-query';
import { cn } from '@/lib/utils';
import type { SnackUser } from '@/types';

/** Matches config('snack.messages.max_length'); the server is the authority. */
const MAX_LENGTH = 12000;

/** Show the counter only once the limit is actually in sight. */
const COUNTER_THRESHOLD = MAX_LENGTH - 500;

/**
 * Where the formatting row's visibility is remembered.
 *
 * A preference about the shape of the composer, not about the message, so it
 * belongs to the browser rather than to the account: it costs a request to sync
 * and nobody expects it to follow them to another machine.
 */
const TOOLBAR_KEY = 'snack.composer.formatting';

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
 * # Formatting
 *
 * The row of markup buttons and the row of actions sit inside the same bordered
 * box as the textarea, so the whole thing reads as one control rather than as a
 * field with a strip of icons stuck to it.
 *
 * Nothing here is a rich-text editor. Each button writes the same marker the
 * author could have typed — see {@see applyCommand} — into the same plain
 * textarea, which is what keeps the message body one piece of source text all
 * the way to the database. The buttons and the keyboard shortcuts run the same
 * commands, and both hand the selection back afterwards so a second press
 * undoes the first.
 *
 * # The @-picker
 *
 * While a mention is being typed, Enter and the arrow keys belong to the picker
 * rather than to the composer: with a list open, Enter means "choose this
 * person", and sending the message half-written instead is the mistake that
 * makes people stop using the picker.
 *
 * # Attachments
 *
 * Files are offered three ways — the plus button, dropping onto the composer,
 * and pasting — because those are the three things people already try. Each one
 * uploads immediately, so the progress bar runs while the author keeps typing;
 * the message claims the finished uploads when it is sent.
 *
 * Passing `workspaceSlug` is what turns any of that on. A composer without one
 * (a preview, a test) has no upload endpoint to talk to and shows no plus
 * button, rather than showing a control that cannot work.
 */
export function MessageComposer({
    channelName,
    disabled = false,
    disabledReason,
    mentionSource,
    typists = [],
    workspaceSlug,
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
    /** Where uploads go. Omitted disables attachments entirely. */
    workspaceSlug?: string;
    /** Called per keystroke; the hook throttles, so this need not. */
    onTyping?: () => void;
    onStopTyping?: () => void;
    onSend: (body: string, attachmentIds: number[]) => void;
}) {
    const [body, setBody] = useState('');
    const [mention, setMention] = useState<MentionQuery | null>(null);
    const [activeIndex, setActiveIndex] = useState(0);
    const [draggingOver, setDraggingOver] = useState(false);
    const [showFormatting, setShowFormatting] = useState(readToolbarPreference);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const filePicker = useRef<HTMLInputElement>(null);

    const candidates = useMentionCandidates(
        mention?.term ?? null,
        mentionSource,
    );

    const uploads = useUploads({ workspaceSlug: workspaceSlug ?? '' });
    const attachmentsEnabled = workspaceSlug !== undefined && !disabled;

    const trimmed = body.trim();

    /*
     * A message may be files alone — "here is the deck" is the attachment — so
     * text is only required when nothing is attached. It still waits for the
     * uploads to finish: sending while bytes are in flight would claim ids that
     * do not exist yet.
     */
    const canSend =
        !disabled &&
        !uploads.isUploading &&
        trimmed.length <= MAX_LENGTH &&
        (trimmed !== '' || uploads.attachmentIds.length > 0);

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

    /**
     * Where the caret was last seen.
     *
     * The emoji picker is a menu, so opening it takes focus out of the
     * textarea, and a textarea that is not focused reports a caret at zero once
     * the menu has been through its own focus handling. Reading that would
     * insert every emoji at the very start of the message — which is where the
     * first one went before this existed.
     */
    const lastSelection = useRef<Selection | null>(null);

    /**
     * Where an edit wants the selection once React has written its value.
     *
     * Setting it any earlier would be setting it on the old text: the textarea
     * is controlled, so the characters only exist in the DOM after the render.
     * Held in a ref rather than in state because it is a one-shot instruction
     * to the DOM, not something the component renders from.
     */
    const pendingSelection = useRef<{
        selection: Selection;
        openMention: boolean;
    } | null>(null);

    /*
     * A layout effect, not a rAF: it runs in the same commit that wrote the
     * value, so the caret is never briefly at the end of the message, and two
     * quick presses of the same button cannot race — the second one reads the
     * selection the first one asked for.
     */
    useLayoutEffect(() => {
        const pending = pendingSelection.current;
        const element = textarea.current;

        if (pending === null || element === null) {
            return;
        }

        pendingSelection.current = null;
        lastSelection.current = pending.selection;

        element.focus();
        element.setSelectionRange(
            pending.selection.start,
            pending.selection.end,
        );
        resize();

        if (pending.openMention) {
            syncMention(element.value, pending.selection.start);
        }
    });

    /** Take an edit from a toolbar button, a shortcut, or the emoji picker. */
    const commit = (edit: Edit, openMention = false) => {
        pendingSelection.current = { selection: edit.selection, openMention };

        setBody(edit.value);
        setMention(null);

        // Formatting is authoring, so the indicator should behave as it does
        // for a keystroke.
        if (edit.value === '') {
            onStopTyping?.();
        } else {
            onTyping?.();
        }
    };

    /** Where an edit should act: the live caret, or the last one seen. */
    const selectionOf = (element: HTMLTextAreaElement): Selection => {
        if (document.activeElement === element) {
            return { start: element.selectionStart, end: element.selectionEnd };
        }

        const remembered = lastSelection.current;
        const end = body.length;

        return remembered === null
            ? { start: end, end }
            : {
                  // Clamped, because the body can have changed since.
                  start: Math.min(remembered.start, end),
                  end: Math.min(remembered.end, end),
              };
    };

    const runCommand = (command: FormatCommand) => {
        const element = textarea.current;

        if (element === null || disabled) {
            return;
        }

        commit(applyCommand(body, selectionOf(element), command));
    };

    /** Hand files to the tray, from the picker, a drop, or a paste. */
    const attach = (files: FileList | File[] | null) => {
        if (!attachmentsEnabled || files === null) {
            return;
        }

        uploads.add(Array.from(files));
    };

    const send = () => {
        if (!canSend) {
            return;
        }

        onSend(trimmed, uploads.attachmentIds);
        uploads.clear();
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
        <div
            className="border-t border-border p-4"
            /*
             * The drop target is the whole composer, not the textarea: aiming a
             * dragged file at a one-line box is fiddly, and every chat app
             * people arrive from accepts a drop anywhere near the bottom.
             *
             * dragOver must preventDefault or the browser navigates to the file
             * instead — which replaces the entire app with a picture.
             */
            onDragOver={(event) => {
                if (!attachmentsEnabled) {
                    return;
                }

                event.preventDefault();
                setDraggingOver(true);
            }}
            onDragLeave={(event) => {
                // Only when the pointer actually left the composer, not merely
                // crossed onto a child — otherwise the highlight flickers.
                if (
                    !event.currentTarget.contains(event.relatedTarget as Node)
                ) {
                    setDraggingOver(false);
                }
            }}
            onDrop={(event) => {
                if (!attachmentsEnabled) {
                    return;
                }

                event.preventDefault();
                setDraggingOver(false);
                attach(event.dataTransfer.files);
            }}
        >
            <TypingIndicator typists={typists} className="mb-1" />

            <div className="relative">
                <MentionPicker
                    people={candidates}
                    activeIndex={activeIndex}
                    onChoose={choose}
                />

                <div
                    className={cn(
                        'overflow-hidden rounded-xl border border-input bg-background',
                        'focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/25',
                        draggingOver && 'border-brand ring-2 ring-brand/25',
                    )}
                >
                    {showFormatting && !disabled && (
                        <FormattingToolbar onCommand={runCommand} />
                    )}

                    <div className="px-3 pt-2">
                        <AttachmentTray
                            uploads={uploads.uploads}
                            onRemove={uploads.remove}
                        />
                    </div>

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
                        /*
                         * React fires this for caret moves, not only for
                         * selections, which makes it the one place that sees
                         * every way the caret can move — typing, clicking,
                         * arrows, dragging.
                         */
                        onSelect={(event) => {
                            lastSelection.current = {
                                start: event.currentTarget.selectionStart,
                                end: event.currentTarget.selectionEnd,
                            };
                        }}
                        onBlur={() => {
                            setMention(null);
                            onStopTyping?.();
                        }}
                        /*
                         * Pasting a screenshot is how most images get shared,
                         * and it arrives as a file on the clipboard rather than
                         * as text. Only handled when there actually are files —
                         * pasting ordinary text must still paste text.
                         */
                        onPaste={(event) => {
                            const files = Array.from(event.clipboardData.files);

                            if (files.length === 0 || !attachmentsEnabled) {
                                return;
                            }

                            event.preventDefault();
                            attach(files);
                        }}
                        onKeyDown={(event) => {
                            /*
                             * Shortcuts first: they all carry a modifier, so
                             * they cannot collide with the picker's arrows or
                             * with Enter.
                             */
                            const command = shortcutFor(event);

                            if (command !== null) {
                                event.preventDefault();
                                runCommand(command);

                                return;
                            }

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
                            'min-h-0 resize-none border-0 bg-transparent px-3 py-2 shadow-none',
                            'focus-visible:ring-0 focus-visible:ring-offset-0',
                        )}
                    />

                    <div className="flex items-center gap-0.5 px-2 pb-2">
                        {attachmentsEnabled && (
                            <>
                                <input
                                    ref={filePicker}
                                    type="file"
                                    multiple
                                    className="sr-only"
                                    aria-label="Attach files"
                                    onChange={(event) => {
                                        attach(event.target.files);

                                        // Cleared so choosing the same file twice
                                        // in a row still fires a change event.
                                        event.target.value = '';
                                    }}
                                />

                                <ComposerAction
                                    label="Attach files"
                                    title={
                                        uploads.isFull
                                            ? 'That is as many files as one message can carry'
                                            : 'Attach files'
                                    }
                                    disabled={uploads.isFull}
                                    onClick={() => filePicker.current?.click()}
                                >
                                    <Plus
                                        aria-hidden="true"
                                        className="size-4.5"
                                    />
                                </ComposerAction>
                            </>
                        )}

                        {!disabled && (
                            <>
                                <ComposerAction
                                    label={
                                        showFormatting
                                            ? 'Hide formatting'
                                            : 'Show formatting'
                                    }
                                    pressed={showFormatting}
                                    onClick={() => {
                                        setShowFormatting((shown) => {
                                            writeToolbarPreference(!shown);

                                            return !shown;
                                        });

                                        textarea.current?.focus();
                                    }}
                                >
                                    <CaseSensitive
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </ComposerAction>

                                <EmojiButton
                                    onPick={(glyph) => {
                                        const element = textarea.current;

                                        if (element === null) {
                                            return;
                                        }

                                        commit(
                                            insertText(
                                                body,
                                                selectionOf(element),
                                                glyph,
                                            ),
                                        );
                                    }}
                                />

                                {mentionSource !== undefined && (
                                    <ComposerAction
                                        label="Mention someone"
                                        onClick={() => {
                                            const element = textarea.current;

                                            if (element === null) {
                                                return;
                                            }

                                            commit(
                                                insertMention(
                                                    body,
                                                    selectionOf(element),
                                                ),
                                                true,
                                            );
                                        }}
                                    >
                                        <AtSign
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </ComposerAction>
                                )}
                            </>
                        )}

                        <div className="ml-auto flex items-center gap-2">
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
            </div>

            <p
                className="mt-1.5 px-1 text-[0.6875rem] text-muted-foreground"
                aria-live="polite"
            >
                {uploads.isUploading ? (
                    // Replaces the shortcut hint rather than sitting beside it:
                    // the reason the send button is dark right now is the one
                    // thing worth saying here.
                    <>Uploading… the message will send once the files finish.</>
                ) : (
                    <>
                        <strong className="font-semibold">Enter</strong> to
                        send,{' '}
                        <strong className="font-semibold">Shift + Enter</strong>{' '}
                        for a new line
                        {attachmentsEnabled && ', or drop files here'}
                    </>
                )}
            </p>
        </div>
    );
}

/**
 * One button in the row under the textarea.
 *
 * They all cancel their own mousedown so the caret stays in the textarea: every
 * one of them acts on the selection, and a button that clears the selection
 * before acting on it would insert at the end of the message instead.
 */
function ComposerAction({
    label,
    title,
    disabled = false,
    pressed,
    onClick,
    children,
}: {
    label: string;
    title?: string;
    disabled?: boolean;
    pressed?: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            title={title ?? label}
            aria-label={label}
            aria-pressed={pressed}
            disabled={disabled}
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
            className={cn(
                'rounded-lg p-1.5 transition-colors',
                disabled
                    ? 'text-muted-foreground/40'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                pressed === true && 'bg-accent text-foreground',
            )}
        >
            {children}
        </button>
    );
}

/**
 * The emoji button.
 *
 * Offers the same set as the reaction picker — see {@see REACTIONS} — because
 * that set is the one the workspace has decided on, and a composer that offered
 * a thousand glyphs next to a reaction row that offers twelve would look like a
 * mistake in one of the two places.
 *
 * Rendered through the project's dropdown primitive rather than as a positioned
 * div: an absolutely-positioned popover is clipped by any scrolling ancestor no
 * matter how high its z-index.
 */
function EmojiButton({ onPick }: { onPick: (glyph: string) => void }) {
    /*
     * Controlled, because the glyphs are plain buttons rather than menu items —
     * a menu item would be a jarring full-width row for a single character —
     * and a plain button inside the panel does not dismiss it. Picking one
     * closes the panel, which is what uncovers the composer again.
     */
    const [open, setOpen] = useState(false);

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    title="Add emoji"
                    aria-label="Add emoji"
                    className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <Smile aria-hidden="true" className="size-4" />
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="start"
                className="flex w-56 flex-wrap gap-0.5 p-1.5"
                // The composer puts focus back on the textarea itself, at the
                // caret the insertion left behind.
                onCloseAutoFocus={(event) => event.preventDefault()}
            >
                {REACTION_SHORTCODES.map((shortcode) => (
                    <button
                        key={shortcode}
                        type="button"
                        title={`:${shortcode}:`}
                        onClick={() => {
                            onPick(emojiGlyph(shortcode));
                            setOpen(false);
                        }}
                        className="rounded p-1 text-base transition-colors hover:bg-accent"
                    >
                        <span aria-hidden="true">{emojiGlyph(shortcode)}</span>
                        <span className="sr-only">{shortcode}</span>
                    </button>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The formatting command a keystroke asks for, if any.
 *
 * Read from `code` rather than `key` for everything but the two unshifted
 * letters, because Shift turns "7" into "&" and "x" into "X" — and on a
 * non-US layout it turns them into something else again. The physical key is
 * what the shortcut is actually named after.
 */
function shortcutFor(
    event: KeyboardEvent<HTMLTextAreaElement>,
): FormatCommand | null {
    if (!event.metaKey && !event.ctrlKey) {
        return null;
    }

    if (!event.shiftKey && !event.altKey) {
        const key = event.key.toLowerCase();

        if (key === 'b') {
            return 'bold';
        }

        return key === 'i' ? 'italic' : null;
    }

    if (!event.shiftKey) {
        return null;
    }

    if (event.altKey) {
        return event.code === 'KeyC' ? 'codeblock' : null;
    }

    switch (event.code) {
        case 'KeyX':
            return 'strike';
        case 'KeyC':
            return 'code';
        case 'Digit7':
            return 'ordered';
        case 'Digit8':
            return 'bullet';
        case 'Digit9':
            return 'quote';
        default:
            return null;
    }
}

/**
 * Whether the formatting row starts open.
 *
 * Open by default: the buttons are how anyone finds out the syntax exists.
 * Storage is read defensively because a browser with cookies and site data
 * blocked throws on access rather than returning null, and that must not take
 * the composer down with it.
 */
function readToolbarPreference(): boolean {
    if (typeof window === 'undefined') {
        return true;
    }

    try {
        return window.localStorage.getItem(TOOLBAR_KEY) !== 'hidden';
    } catch {
        return true;
    }
}

function writeToolbarPreference(shown: boolean): void {
    try {
        window.localStorage.setItem(TOOLBAR_KEY, shown ? 'shown' : 'hidden');
    } catch {
        // A preference is not worth an exception; it simply will not persist.
    }
}
