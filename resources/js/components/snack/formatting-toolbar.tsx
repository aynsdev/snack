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
import type { FormatCommand } from '@/lib/composer-format';
import { cn } from '@/lib/utils';

/**
 * The composer's formatting row.
 *
 * Every button writes the marker a person could have typed themselves — see
 * {@see applyCommand} — so the toolbar is a shortcut for the syntax rather than
 * a second way of authoring a message. That is also why the row stops where the
 * grammar does: there is no underline button, because a message body has no
 * underline, and a control that writes characters which render as themselves is
 * worse than an absent one.
 *
 * Pressing a button must not take the caret out of the textarea, so each one
 * cancels its own mousedown. The click still fires, and the composer puts the
 * selection back where the edit left it.
 */
export function FormattingToolbar({
    onCommand,
}: {
    onCommand: (command: FormatCommand) => void;
}) {
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
                            onCommand={onCommand}
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
    /** The key part of the shortcut, written the way the hint reads it. */
    shortcut?: string;
};

/**
 * The buttons, in three groups: what a run of text looks like, how lines are
 * arranged, and code.
 */
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
    onCommand,
}: {
    button: FormatButtonSpec;
    onCommand: (command: FormatCommand) => void;
}) {
    const Icon = button.icon;

    return (
        <button
            type="button"
            aria-label={button.label}
            title={
                button.shortcut === undefined
                    ? button.label
                    : `${button.label} (${modifierLabel()}${button.shortcut})`
            }
            // Keeps the selection in the textarea; see the note on the toolbar.
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => onCommand(button.command)}
            className={cn(
                'rounded-md p-1.5 text-muted-foreground transition-colors',
                'hover:bg-accent hover:text-foreground',
                'focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:outline-none',
            )}
        >
            <Icon aria-hidden="true" className="size-4" />
        </button>
    );
}

/**
 * How this machine writes the shortcut modifier.
 *
 * Read at call time rather than at module load so it is never baked into a
 * server-rendered page, where there is no keyboard to describe.
 */
function modifierLabel(): string {
    if (typeof navigator === 'undefined') {
        return 'Ctrl ';
    }

    return /mac|iphone|ipad/i.test(navigator.userAgent) ? '⌘ ' : 'Ctrl ';
}
