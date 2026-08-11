import type { Editor } from '@tiptap/core';
import { useEditorState } from '@tiptap/react';
import {
    Bold,
    Code,
    Italic,
    List,
    ListOrdered,
    SquareCode,
    Strikethrough,
    TextQuote,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { runMessageFormatCommand } from '@/lib/message-composer-commands';
import type { FormatCommand } from '@/lib/message-composer-commands';
import { cn } from '@/lib/utils';

/** Tiptap controls for exactly the formatting Snack messages can render. */
export function FormattingToolbar({ editor }: { editor: Editor }) {
    const modifier = useModifierLabel();
    const active = useEditorState({
        editor,
        selector: ({ editor: current }) => ({
            bold: current?.isActive('bold') ?? false,
            italic: current?.isActive('italic') ?? false,
            strike: current?.isActive('strike') ?? false,
            ordered: current?.isActive('orderedList') ?? false,
            bullet: current?.isActive('bulletList') ?? false,
            quote: current?.isActive('blockquote') ?? false,
            code: current?.isActive('code') ?? false,
            codeblock: current?.isActive('codeBlock') ?? false,
        }),
    });

    return (
        <div
            role="toolbar"
            aria-label="Formatting"
            aria-orientation="horizontal"
            className="flex flex-wrap items-center gap-0.5 border-b border-border px-2 py-1.5"
        >
            {GROUPS.map((group, index) => (
                <div key={index} className="flex items-center gap-0.5">
                    {index > 0 && (
                        <span
                            aria-hidden="true"
                            className="mx-1 h-4 w-px bg-border"
                        />
                    )}

                    {group.map((button) => (
                        <FormatButton
                            key={button.command}
                            button={button}
                            modifier={modifier}
                            pressed={active?.[button.command] ?? false}
                            onCommand={(command) =>
                                runMessageFormatCommand(editor, command)
                            }
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}

type FormatButtonSpec = {
    command: FormatCommand;
    label: string;
    icon: LucideIcon;
    shortcut?: string;
};

const GROUPS: FormatButtonSpec[][] = [
    [
        { command: 'bold', label: 'Bold', icon: Bold, shortcut: 'B' },
        { command: 'italic', label: 'Italic', icon: Italic, shortcut: 'I' },
        {
            command: 'strike',
            label: 'Strikethrough',
            icon: Strikethrough,
            shortcut: 'Shift X',
        },
    ],
    [
        {
            command: 'ordered',
            label: 'Numbered list',
            icon: ListOrdered,
            shortcut: 'Shift 7',
        },
        {
            command: 'bullet',
            label: 'Bulleted list',
            icon: List,
            shortcut: 'Shift 8',
        },
        {
            command: 'quote',
            label: 'Blockquote',
            icon: TextQuote,
            shortcut: 'Shift 9',
        },
    ],
    [
        {
            command: 'code',
            label: 'Code',
            icon: Code,
            shortcut: 'Shift C',
        },
        {
            command: 'codeblock',
            label: 'Code block',
            icon: SquareCode,
            shortcut: 'Alt Shift C',
        },
    ],
];

function FormatButton({
    button,
    modifier,
    pressed,
    onCommand,
}: {
    button: FormatButtonSpec;
    modifier: string;
    pressed: boolean;
    onCommand: (command: FormatCommand) => void;
}) {
    const Icon = button.icon;

    return (
        <button
            type="button"
            aria-label={button.label}
            aria-pressed={pressed}
            title={
                button.shortcut === undefined
                    ? button.label
                    : `${button.label} (${modifier}${button.shortcut})`
            }
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => onCommand(button.command)}
            className={cn(
                'rounded-md p-1.5 text-muted-foreground transition-colors',
                'hover:bg-accent hover:text-foreground',
                'focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:outline-none',
                pressed && 'bg-accent text-foreground',
            )}
        >
            <Icon aria-hidden="true" className="size-4" />
        </button>
    );
}

const NEVER_CHANGES = () => () => {};

function useModifierLabel(): string {
    return useSyncExternalStore(NEVER_CHANGES, clientModifier, serverModifier);
}

function clientModifier(): string {
    return /mac|iphone|ipad/i.test(navigator.userAgent) ? '⌘ ' : 'Ctrl ';
}

function serverModifier(): string {
    return 'Ctrl ';
}
