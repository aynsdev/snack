import type { Editor } from '@tiptap/core';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    AtSign,
    CaseSensitive,
    Plus,
    SendHorizontal,
    Smile,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
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
import type { Typist } from '@/hooks/use-typing';
import { useUploads } from '@/hooks/use-uploads';
import { REACTION_SHORTCODES, emojiGlyph } from '@/lib/emoji';
import { runMessageFormatCommand } from '@/lib/message-composer-commands';
import type { FormatCommand } from '@/lib/message-composer-commands';
import { serializeMessageDocument } from '@/lib/message-composer-document';
import { cn } from '@/lib/utils';
import type { SnackUser } from '@/types';

const MAX_LENGTH = 12000;
const COUNTER_THRESHOLD = MAX_LENGTH - 500;
const TOOLBAR_KEY = 'snack.composer.formatting';
const PARTIAL_HANDLE = /(?:^|[^\w@])@([A-Za-z0-9_.-]*)$/;

type ComposerMention = {
    term: string;
    from: number;
    to: number;
};

/**
 * Snack's message composer, powered by Tiptap but serialized back into the
 * existing safe message grammar before sending. Rich formatting is visible as
 * the author types without changing the server contract or stored messages.
 */
export function MessageComposer({
    channelName,
    disabled = false,
    disabledReason,
    mentionSource,
    typists = [],
    replying = null,
    workspaceSlug,
    onTyping,
    onStopTyping,
    onSend,
}: {
    channelName: string;
    disabled?: boolean;
    disabledReason?: string;
    mentionSource?: MentionSource;
    typists?: Typist[];
    /** The assistant's name while it is working on an answer here, else null. */
    replying?: string | null;
    workspaceSlug?: string;
    onTyping?: () => void;
    onStopTyping?: () => void;
    onSend: (body: string, attachmentIds: number[]) => void;
}) {
    const [body, setBody] = useState('');
    const [mention, setMention] = useState<ComposerMention | null>(null);
    const [activeIndex, setActiveIndex] = useState(0);
    const [draggingOver, setDraggingOver] = useState(false);
    const [showFormatting, setShowFormatting] = useState(readToolbarPreference);
    const filePicker = useRef<HTMLInputElement>(null);
    const editorRef = useRef<Editor | null>(null);
    const mentionRef = useRef<ComposerMention | null>(null);
    const candidatesRef = useRef<SnackUser[]>([]);
    const activeIndexRef = useRef(0);
    const chooseRef = useRef<(person: SnackUser) => void>(() => undefined);
    const sendRef = useRef<() => void>(() => undefined);

    const candidates = useMentionCandidates(
        mention?.term ?? null,
        mentionSource,
    );
    const uploads = useUploads({ workspaceSlug: workspaceSlug ?? '' });
    const attachmentsEnabled = workspaceSlug !== undefined && !disabled;
    const trimmed = body.trim();
    const canSend =
        !disabled &&
        !uploads.isUploading &&
        trimmed.length <= MAX_LENGTH &&
        (trimmed !== '' || uploads.attachmentIds.length > 0);
    const attach = useCallback(
        (files: FileList | File[] | null) => {
            if (!attachmentsEnabled || files === null) {
                return;
            }

            uploads.add(Array.from(files));
        },
        [attachmentsEnabled, uploads],
    );

    const syncMention = useCallback(
        (current: Editor) => {
            if (mentionSource === undefined) {
                setMention(null);

                return;
            }

            const selection = current.state.selection;

            if (!selection.empty) {
                setMention(null);

                return;
            }

            const before = current.state.doc.textBetween(
                0,
                selection.from,
                '\n',
                '\n',
            );
            const match = before.match(PARTIAL_HANDLE);

            if (match === null) {
                setMention(null);

                return;
            }

            const term = match[1];

            setMention({
                term,
                from: selection.from - term.length - 1,
                to: selection.from,
            });
            setActiveIndex(0);
        },
        [mentionSource],
    );

    const editor = useEditor({
        immediatelyRender: false,
        editable: !disabled,
        extensions: [
            StarterKit.configure({
                heading: false,
                horizontalRule: false,
                link: {
                    openOnClick: false,
                    autolink: true,
                    linkOnPaste: true,
                    defaultProtocol: 'https',
                    protocols: ['http', 'https'],
                },
            }),
            Placeholder.configure({
                placeholder: `Message ${channelName}`,
            }),
            CharacterCount.configure({ limit: MAX_LENGTH }),
        ],
        editorProps: {
            attributes: {
                role: 'textbox',
                'aria-label': `Message ${channelName}`,
                'aria-multiline': 'true',
                'aria-autocomplete':
                    mentionSource === undefined ? 'none' : 'list',
                class: 'message-tiptap snack-scroll',
            },
            handleKeyDown: (_view, event) => {
                const current = editorRef.current;

                if (current === null) {
                    return false;
                }

                const command = shortcutFor(event);

                if (command !== null) {
                    event.preventDefault();
                    runMessageFormatCommand(current, command);

                    return true;
                }

                if (
                    mentionRef.current !== null &&
                    candidatesRef.current.length > 0
                ) {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        setActiveIndex(
                            (index) =>
                                (index + 1) % candidatesRef.current.length,
                        );

                        return true;
                    }

                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        setActiveIndex(
                            (index) =>
                                (index - 1 + candidatesRef.current.length) %
                                candidatesRef.current.length,
                        );

                        return true;
                    }

                    if (event.key === 'Enter' || event.key === 'Tab') {
                        event.preventDefault();
                        chooseRef.current(
                            candidatesRef.current[activeIndexRef.current],
                        );

                        return true;
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        setMention(null);

                        return true;
                    }
                }

                if (
                    event.key === 'Enter' &&
                    event.shiftKey &&
                    (current.isActive('bulletList') ||
                        current.isActive('orderedList'))
                ) {
                    event.preventDefault();
                    current.chain().focus().splitListItem('listItem').run();

                    return true;
                }

                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    sendRef.current();

                    return true;
                }

                return false;
            },
        },
        onCreate: ({ editor: current }) => {
            editorRef.current = current;
        },
        onUpdate: ({ editor: current }) => {
            const next = serializeMessageDocument(current.getJSON());

            setBody(next);
            syncMention(current);

            if (next.trim() === '') {
                onStopTyping?.();
            } else {
                onTyping?.();
            }
        },
        onSelectionUpdate: ({ editor: current }) => syncMention(current),
        onBlur: () => {
            setMention(null);
            onStopTyping?.();
        },
        onDestroy: () => {
            editorRef.current = null;
        },
    });

    const choose = useCallback((person: SnackUser) => {
        const current = editorRef.current;
        const query = mentionRef.current;

        if (current === null || query === null) {
            return;
        }

        const after = current.state.doc.textBetween(
            query.to,
            Math.min(query.to + 1, current.state.doc.content.size),
            '\n',
            '\n',
        );
        const needsSpace = after === '' || /^[A-Za-z0-9_@#]/.test(after);

        current
            .chain()
            .focus()
            .insertContentAt(
                { from: query.from, to: query.to },
                `@${person.username}${needsSpace ? ' ' : ''}`,
            )
            .run();
        setMention(null);
    }, []);

    const send = useCallback(() => {
        const current = editorRef.current;

        if (current === null || disabled || uploads.isUploading) {
            return;
        }

        const message = serializeMessageDocument(current.getJSON()).trim();

        if (
            message.length > MAX_LENGTH ||
            (message === '' && uploads.attachmentIds.length === 0)
        ) {
            return;
        }

        onSend(message, uploads.attachmentIds);
        uploads.clear();
        current.commands.clearContent(true);
        current.commands.focus();
        setBody('');
        setMention(null);
        onStopTyping?.();
    }, [disabled, onSend, onStopTyping, uploads]);

    useEffect(() => {
        mentionRef.current = mention;
        candidatesRef.current = candidates;
        activeIndexRef.current = activeIndex;
        chooseRef.current = choose;
        sendRef.current = send;
    }, [activeIndex, candidates, choose, mention, send]);

    useEffect(() => {
        if (editor === null) {
            return;
        }

        editor.view.dom.setAttribute(
            'aria-expanded',
            mention !== null && candidates.length > 0 ? 'true' : 'false',
        );
    }, [candidates.length, editor, mention]);

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
            onDragOver={(event) => {
                if (!attachmentsEnabled) {
                    return;
                }

                event.preventDefault();
                setDraggingOver(true);
            }}
            onDragLeave={(event) => {
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
            <TypingIndicator
                typists={typists}
                replying={replying}
                className="mb-1"
            />

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
                    {showFormatting && !disabled && editor !== null && (
                        <FormattingToolbar editor={editor} />
                    )}

                    <div className="px-3 pt-2">
                        <AttachmentTray
                            uploads={uploads.uploads}
                            onRemove={uploads.remove}
                        />
                    </div>

                    <EditorContent
                        editor={editor}
                        onPasteCapture={(event) => {
                            const files = Array.from(event.clipboardData.files);

                            if (files.length === 0 || !attachmentsEnabled) {
                                return;
                            }

                            event.preventDefault();
                            attach(files);
                        }}
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
                                        editor?.commands.focus();
                                    }}
                                >
                                    <CaseSensitive
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </ComposerAction>

                                <EmojiButton
                                    onPick={(glyph) =>
                                        editor
                                            ?.chain()
                                            .focus()
                                            .insertContent(glyph)
                                            .run()
                                    }
                                />

                                {mentionSource !== undefined && (
                                    <ComposerAction
                                        label="Mention someone"
                                        onClick={() => {
                                            if (editor === null) {
                                                return;
                                            }

                                            const position =
                                                editor.state.selection.from;
                                            const before =
                                                editor.state.doc.textBetween(
                                                    Math.max(0, position - 1),
                                                    position,
                                                    '\n',
                                                    '\n',
                                                );
                                            const prefix =
                                                before !== '' &&
                                                !/\s$/.test(before)
                                                    ? ' @'
                                                    : '@';

                                            editor
                                                .chain()
                                                .focus()
                                                .insertContent(prefix)
                                                .run();
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
                                aria-label="Send message"
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

function EmojiButton({ onPick }: { onPick: (glyph: string) => void }) {
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

function shortcutFor(event: globalThis.KeyboardEvent): FormatCommand | null {
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
        // This preference is nonessential when browser storage is unavailable.
    }
}
