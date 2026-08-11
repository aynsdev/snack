import type { Editor } from '@tiptap/core';

export type FormatCommand =
    | 'bold'
    | 'italic'
    | 'strike'
    | 'ordered'
    | 'bullet'
    | 'quote'
    | 'code'
    | 'codeblock';

export function runMessageFormatCommand(
    editor: Editor,
    command: FormatCommand,
): void {
    const chain = editor.chain().focus();

    switch (command) {
        case 'bold':
            chain.toggleBold().run();
            break;
        case 'italic':
            chain.toggleItalic().run();
            break;
        case 'strike':
            chain.toggleStrike().run();
            break;
        case 'ordered':
            chain.toggleOrderedList().run();
            break;
        case 'bullet':
            chain.toggleBulletList().run();
            break;
        case 'quote':
            chain.toggleBlockquote().run();
            break;
        case 'code':
            chain.toggleCode().run();
            break;
        case 'codeblock':
            chain.toggleCodeBlock().run();
            break;
    }
}
