import type { Editor, JSONContent } from '@tiptap/core';
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import {
    Details,
    DetailsContent,
    DetailsSummary,
} from '@tiptap/extension-details';
import Highlight from '@tiptap/extension-highlight';
import Image from '@tiptap/extension-image';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import Mathematics from '@tiptap/extension-mathematics';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { TableKit } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyleKit } from '@tiptap/extension-text-style';
import Typography from '@tiptap/extension-typography';
import UniqueID from '@tiptap/extension-unique-id';
import Youtube from '@tiptap/extension-youtube';
import { CharacterCount, Placeholder, Selection } from '@tiptap/extensions';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import { BubbleMenu } from '@tiptap/react/menus';
import StarterKit from '@tiptap/starter-kit';
import { common, createLowlight } from 'lowlight';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    Braces,
    ChevronDown,
    Code,
    Columns3,
    Eraser,
    Heading1,
    Heading2,
    Heading3,
    Highlighter,
    ImageIcon,
    Italic,
    Link2,
    List,
    ListChecks,
    ListOrdered,
    Merge,
    Minus,
    Palette,
    Pilcrow,
    Plus,
    Quote,
    Redo2,
    Rows3,
    Sigma,
    Smile,
    Split,
    Strikethrough,
    SubscriptIcon,
    SuperscriptIcon,
    Table2,
    Trash2,
    Underline,
    Undo2,
    YoutubeIcon,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import 'katex/dist/katex.min.css';

const lowlight = createLowlight(common);
const CHARACTER_LIMIT = 200_000;
const EMOJIS = ['👍', '✅', '🎯', '💡', '🚀', '📌', '❤️', '👀'];

type PromptKind = 'link' | 'image' | 'youtube' | 'math';

type Props = {
    content: JSONContent;
    editable: boolean;
    onChange?: (content: JSONContent, text: string) => void;
};

export function CanvasRichTextEditor({ content, editable, onChange }: Props) {
    const [prompt, setPrompt] = useState<PromptKind | null>(null);
    const editor = useEditor({
        immediatelyRender: true,
        editable,
        content,
        extensions: [
            StarterKitWithCodeHighlighting,
            CodeBlockLowlight.configure({ lowlight }),
            TaskList,
            TaskItem.configure({ nested: true }),
            TableKit.configure({ table: { resizable: true } }),
            Image.configure({ allowBase64: false }),
            Youtube.configure({ nocookie: true }),
            Details,
            DetailsSummary,
            DetailsContent,
            Mathematics.configure({ katexOptions: { throwOnError: false } }),
            TextStyleKit,
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Highlight.configure({ multicolor: true }),
            Subscript,
            Superscript,
            Typography,
            Placeholder.configure({
                placeholder: ({ node }) =>
                    node.type.name === 'heading'
                        ? 'Heading'
                        : 'Time to document your genius…',
            }),
            CharacterCount.configure({ limit: CHARACTER_LIMIT }),
            Selection,
            UniqueID.configure({
                types: [
                    'paragraph',
                    'heading',
                    'blockquote',
                    'codeBlock',
                    'table',
                    'taskList',
                    'details',
                ],
            }),
        ],
        editorProps: {
            attributes: {
                class: 'canvas-tiptap min-h-[50vh] outline-none',
                'aria-label': 'Canvas content',
            },
        },
        onUpdate: ({ editor: current }) => {
            onChange?.(
                current.getJSON(),
                current.getText({ blockSeparator: '\n' }),
            );
        },
    });

    const state = useEditorState({
        editor,
        selector: ({ editor: current }) => ({
            bold: current?.isActive('bold') ?? false,
            italic: current?.isActive('italic') ?? false,
            underline: current?.isActive('underline') ?? false,
            strike: current?.isActive('strike') ?? false,
            code: current?.isActive('code') ?? false,
            highlight: current?.isActive('highlight') ?? false,
            subscript: current?.isActive('subscript') ?? false,
            superscript: current?.isActive('superscript') ?? false,
            canUndo: current?.can().chain().undo().run() ?? false,
            canRedo: current?.can().chain().redo().run() ?? false,
            characters: current?.storage.characterCount?.characters() ?? 0,
        }),
    });

    if (editor === null) {
        return (
            <div className="min-h-[50vh]" aria-label="Loading Canvas editor" />
        );
    }

    return (
        <div className="relative mt-8 flex min-h-[50vh] flex-1 flex-col">
            <EditorContent editor={editor} />

            {editable && (
                <>
                    <BubbleMenu editor={editor}>
                        <div className="flex items-center gap-0.5 rounded-lg border border-border bg-popover p-1 shadow-md">
                            <MarkButton
                                editor={editor}
                                label="Bold"
                                active={state?.bold ?? false}
                                command="bold"
                            >
                                <Bold />
                            </MarkButton>
                            <MarkButton
                                editor={editor}
                                label="Italic"
                                active={state?.italic ?? false}
                                command="italic"
                            >
                                <Italic />
                            </MarkButton>
                            <MarkButton
                                editor={editor}
                                label="Underline"
                                active={state?.underline ?? false}
                                command="underline"
                            >
                                <Underline />
                            </MarkButton>
                            <MarkButton
                                editor={editor}
                                label="Strikethrough"
                                active={state?.strike ?? false}
                                command="strike"
                            >
                                <Strikethrough />
                            </MarkButton>
                            <ToolbarButton
                                label="Add link"
                                onClick={() => setPrompt('link')}
                            >
                                <Link2 />
                            </ToolbarButton>
                        </div>
                    </BubbleMenu>

                    <div className="sticky bottom-4 z-20 mt-auto flex justify-center pt-10">
                        <div
                            role="toolbar"
                            aria-label="Canvas editor tools"
                            className="flex max-w-full flex-wrap items-center gap-0.5 rounded-xl border border-border bg-popover p-1.5 shadow-lg"
                        >
                            <InsertMenu editor={editor} onPrompt={setPrompt} />
                            <TextStyleMenu editor={editor} />

                            <MarkButton
                                editor={editor}
                                label="Bold"
                                active={state?.bold ?? false}
                                command="bold"
                            >
                                <Bold />
                            </MarkButton>
                            <MarkButton
                                editor={editor}
                                label="Italic"
                                active={state?.italic ?? false}
                                command="italic"
                            >
                                <Italic />
                            </MarkButton>
                            <MarkButton
                                editor={editor}
                                label="Underline"
                                active={state?.underline ?? false}
                                command="underline"
                            >
                                <Underline />
                            </MarkButton>
                            <MarkButton
                                editor={editor}
                                label="Strikethrough"
                                active={state?.strike ?? false}
                                command="strike"
                            >
                                <Strikethrough />
                            </MarkButton>
                            <MarkButton
                                editor={editor}
                                label="Inline code"
                                active={state?.code ?? false}
                                command="code"
                            >
                                <Code />
                            </MarkButton>
                            <ToolbarButton
                                label="Highlight"
                                pressed={state?.highlight}
                                onClick={() =>
                                    editor
                                        .chain()
                                        .focus()
                                        .toggleHighlight()
                                        .run()
                                }
                            >
                                <Highlighter />
                            </ToolbarButton>

                            <AlignmentMenu editor={editor} />
                            <EmojiMenu editor={editor} />
                            <TableMenu editor={editor} />

                            <ToolbarButton
                                label="Undo"
                                disabled={!state?.canUndo}
                                onClick={() =>
                                    editor.chain().focus().undo().run()
                                }
                            >
                                <Undo2 />
                            </ToolbarButton>
                            <ToolbarButton
                                label="Redo"
                                disabled={!state?.canRedo}
                                onClick={() =>
                                    editor.chain().focus().redo().run()
                                }
                            >
                                <Redo2 />
                            </ToolbarButton>
                        </div>
                    </div>

                    <p className="mt-2 text-center text-xs text-muted-foreground tabular-nums">
                        {(state?.characters ?? 0).toLocaleString()} /{' '}
                        {CHARACTER_LIMIT.toLocaleString()} characters
                    </p>

                    <EditorPrompt
                        kind={prompt}
                        onClose={() => setPrompt(null)}
                        onSubmit={(value) => {
                            applyPrompt(editor, prompt, value);
                            setPrompt(null);
                        }}
                    />
                </>
            )}
        </div>
    );
}

const StarterKitWithCodeHighlighting = StarterKit.configure({
    codeBlock: false,
    link: {
        openOnClick: false,
        autolink: true,
        defaultProtocol: 'https',
        protocols: ['http', 'https', 'mailto', 'tel'],
    },
});

type MarkCommand = 'bold' | 'italic' | 'underline' | 'strike' | 'code';

function MarkButton({
    editor,
    label,
    active,
    command,
    children,
}: {
    editor: Editor;
    label: string;
    active: boolean;
    command: MarkCommand;
    children: React.ReactNode;
}) {
    const run = () => {
        const chain = editor.chain().focus();

        switch (command) {
            case 'bold':
                chain.toggleBold().run();
                break;
            case 'italic':
                chain.toggleItalic().run();
                break;
            case 'underline':
                chain.toggleUnderline().run();
                break;
            case 'strike':
                chain.toggleStrike().run();
                break;
            case 'code':
                chain.toggleCode().run();
                break;
        }
    };

    return (
        <ToolbarButton label={label} pressed={active} onClick={run}>
            {children}
        </ToolbarButton>
    );
}

function ToolbarButton({
    label,
    pressed,
    disabled,
    onClick,
    children,
}: {
    label: string;
    pressed?: boolean;
    disabled?: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            aria-pressed={pressed}
            disabled={disabled}
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
            className={cn(
                'flex size-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:bg-accent focus-visible:outline-none disabled:pointer-events-none disabled:opacity-40 [&>svg]:size-4',
                pressed && 'bg-accent text-foreground',
            )}
        >
            {children}
        </button>
    );
}

function InsertMenu({
    editor,
    onPrompt,
}: {
    editor: Editor;
    onPrompt: (kind: PromptKind) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Insert content"
                    className="text-brand-foreground flex size-9 items-center justify-center rounded-full bg-brand hover:bg-brand-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Plus className="size-5" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" className="w-60">
                <DropdownMenuLabel>Insert content</DropdownMenuLabel>
                <DropdownMenuItem
                    onSelect={() => editor.chain().focus().setParagraph().run()}
                >
                    <Pilcrow /> Paragraph
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleHeading({ level: 1 }).run()
                    }
                >
                    <Heading1 /> Large heading
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleHeading({ level: 2 }).run()
                    }
                >
                    <Heading2 /> Heading
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleHeading({ level: 3 }).run()
                    }
                >
                    <Heading3 /> Small heading
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleBulletList().run()
                    }
                >
                    <List /> Bulleted list
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleOrderedList().run()
                    }
                >
                    <ListOrdered /> Numbered list
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleTaskList().run()
                    }
                >
                    <ListChecks /> Checklist
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleBlockquote().run()
                    }
                >
                    <Quote /> Quote
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleCodeBlock().run()
                    }
                >
                    <Braces /> Code block
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() => editor.chain().focus().setDetails().run()}
                >
                    <ChevronDown /> Collapsible section
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={() =>
                        editor
                            .chain()
                            .focus()
                            .insertTable({
                                rows: 3,
                                cols: 3,
                                withHeaderRow: true,
                            })
                            .run()
                    }
                >
                    <Table2 /> Table
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onPrompt('image')}>
                    <ImageIcon /> Image from URL
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onPrompt('youtube')}>
                    <YoutubeIcon /> YouTube video
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onPrompt('math')}>
                    <Sigma /> Math formula
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().setHorizontalRule().run()
                    }
                >
                    <Minus /> Divider
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function TextStyleMenu({ editor }: { editor: Editor }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Text styles"
                    className="flex h-9 items-center gap-1 rounded-lg px-2 text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    Aa <ChevronDown className="size-3" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" className="w-52">
                <DropdownMenuLabel>Text size</DropdownMenuLabel>
                {[
                    ['Small', '12px'],
                    ['Normal', '16px'],
                    ['Large', '20px'],
                    ['Extra large', '28px'],
                ].map(([label, size]) => (
                    <DropdownMenuItem
                        key={size}
                        onSelect={() =>
                            editor.chain().focus().setFontSize(size).run()
                        }
                    >
                        {label}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleSubscript().run()
                    }
                >
                    <SubscriptIcon /> Subscript
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().toggleSuperscript().run()
                    }
                >
                    <SuperscriptIcon /> Superscript
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor
                            .chain()
                            .focus()
                            .unsetAllMarks()
                            .clearNodes()
                            .run()
                    }
                >
                    <Eraser /> Clear formatting
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuLabel>Text color</DropdownMenuLabel>
                <div className="flex items-center gap-2 px-2 py-1.5">
                    <Palette className="size-4 text-muted-foreground" />
                    <input
                        type="color"
                        aria-label="Choose text color"
                        className="h-7 flex-1 cursor-pointer rounded border border-border bg-transparent"
                        onChange={(event) =>
                            editor
                                .chain()
                                .focus()
                                .setColor(event.target.value)
                                .run()
                        }
                    />
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function AlignmentMenu({ editor }: { editor: Editor }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <ToolbarButton label="Text alignment" onClick={() => undefined}>
                    <AlignLeft />
                </ToolbarButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top">
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().setTextAlign('left').run()
                    }
                >
                    <AlignLeft /> Left
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().setTextAlign('center').run()
                    }
                >
                    <AlignCenter /> Center
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().setTextAlign('right').run()
                    }
                >
                    <AlignRight /> Right
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function EmojiMenu({ editor }: { editor: Editor }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <ToolbarButton label="Insert emoji" onClick={() => undefined}>
                    <Smile />
                </ToolbarButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                side="top"
                className="grid grid-cols-4 gap-1 p-2"
            >
                {EMOJIS.map((emoji) => (
                    <DropdownMenuItem
                        key={emoji}
                        aria-label={`Insert ${emoji}`}
                        onSelect={() =>
                            editor.chain().focus().insertContent(emoji).run()
                        }
                        className="flex size-9 justify-center p-0 text-lg"
                    >
                        {emoji}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function TableMenu({ editor }: { editor: Editor }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <ToolbarButton label="Table tools" onClick={() => undefined}>
                    <Table2 />
                </ToolbarButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" className="w-52">
                {!editor.isActive('table') ? (
                    <DropdownMenuItem
                        onSelect={() =>
                            editor
                                .chain()
                                .focus()
                                .insertTable({
                                    rows: 3,
                                    cols: 3,
                                    withHeaderRow: true,
                                })
                                .run()
                        }
                    >
                        <Table2 /> Insert table
                    </DropdownMenuItem>
                ) : (
                    <>
                        <DropdownMenuItem
                            onSelect={() =>
                                editor.chain().focus().addColumnAfter().run()
                            }
                        >
                            <Columns3 /> Add column
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() =>
                                editor.chain().focus().addRowAfter().run()
                            }
                        >
                            <Rows3 /> Add row
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() =>
                                editor.chain().focus().mergeCells().run()
                            }
                        >
                            <Merge /> Merge cells
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() =>
                                editor.chain().focus().splitCell().run()
                            }
                        >
                            <Split /> Split cell
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() =>
                                editor.chain().focus().deleteTable().run()
                            }
                        >
                            <Trash2 /> Delete table
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function EditorPrompt({
    kind,
    onClose,
    onSubmit,
}: {
    kind: PromptKind | null;
    onClose: () => void;
    onSubmit: (value: string) => void;
}) {
    const [value, setValue] = useState('');
    const copy = promptCopy(kind);

    return (
        <Dialog
            open={kind !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setValue('');
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{copy.title}</DialogTitle>
                    <DialogDescription>{copy.description}</DialogDescription>
                </DialogHeader>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit(value.trim());
                        setValue('');
                    }}
                >
                    <Input
                        value={value}
                        onChange={(event) => setValue(event.target.value)}
                        placeholder={copy.placeholder}
                        aria-label={copy.title}
                        autoFocus
                        required
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit">Insert</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function applyPrompt(editor: Editor, kind: PromptKind | null, value: string) {
    if (value === '' || kind === null) {
        return;
    }

    if (kind === 'link') {
        editor
            .chain()
            .focus()
            .extendMarkRange('link')
            .setLink({ href: value })
            .run();
    } else if (kind === 'image') {
        editor.chain().focus().setImage({ src: value }).run();
    } else if (kind === 'youtube') {
        editor.chain().focus().setYoutubeVideo({ src: value }).run();
    } else {
        editor.chain().focus().insertInlineMath({ latex: value }).run();
    }
}

function promptCopy(kind: PromptKind | null) {
    switch (kind) {
        case 'image':
            return {
                title: 'Insert image',
                description: 'Paste a public HTTPS image URL.',
                placeholder: 'https://example.com/image.jpg',
            };
        case 'youtube':
            return {
                title: 'Insert YouTube video',
                description: 'Paste a YouTube video URL.',
                placeholder: 'https://youtube.com/watch?v=…',
            };
        case 'math':
            return {
                title: 'Insert math formula',
                description: 'Enter a LaTeX expression.',
                placeholder: 'E = mc^2',
            };
        default:
            return {
                title: 'Add link',
                description: 'Paste an HTTP, HTTPS, email, or telephone link.',
                placeholder: 'https://example.com',
            };
    }
}
