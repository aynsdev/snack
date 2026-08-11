import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { MessageComposer } from '@/components/snack/message-composer';
import type { SnackUser } from '@/types';

const bo: SnackUser = {
    id: 2,
    name: 'Bo Peep',
    username: 'bo',
    initials: 'BP',
    avatar_url: null,
    title: null,
    department: null,
    presence_status: 'online',
    status_emoji: null,
    status_message: null,
};

/**
 * The composer.
 *
 * Enter-sends and Shift+Enter-newlines is the convention people arrive with;
 * getting it backwards means every multi-line message is sent half-written.
 */
describe('MessageComposer', () => {
    it('sends on Enter', async () => {
        const onSend = vi.fn();
        const user = userEvent.setup();

        render(<MessageComposer channelName="#general" onSend={onSend} />);

        await user.type(screen.getByRole('textbox'), 'Hello{Enter}');

        expect(onSend).toHaveBeenCalledWith('Hello', []);
    });

    it('inserts a newline on Shift+Enter instead of sending', async () => {
        const onSend = vi.fn();
        const user = userEvent.setup();

        render(<MessageComposer channelName="#general" onSend={onSend} />);

        const box = screen.getByRole('textbox');
        await user.type(box, 'first{Shift>}{Enter}{/Shift}second');

        expect(onSend).not.toHaveBeenCalled();
        expect(box).toHaveTextContent('firstsecond');
        expect(box.innerHTML).toContain('<br');
    });

    it('clears after sending', async () => {
        const user = userEvent.setup();

        render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

        const box = screen.getByRole('textbox');
        await user.type(box, 'Hello{Enter}');

        expect(box).toHaveTextContent('');
    });

    it('refuses to send a whitespace-only message', async () => {
        const onSend = vi.fn();
        const user = userEvent.setup();

        render(<MessageComposer channelName="#general" onSend={onSend} />);

        await user.type(screen.getByRole('textbox'), '   {Enter}');

        expect(onSend).not.toHaveBeenCalled();
    });

    it('trims the message before sending', async () => {
        const onSend = vi.fn();
        const user = userEvent.setup();

        render(<MessageComposer channelName="#general" onSend={onSend} />);

        await user.type(screen.getByRole('textbox'), '  padded  {Enter}');

        expect(onSend).toHaveBeenCalledWith('padded', []);
    });

    it('sends from the send button', async () => {
        const onSend = vi.fn();
        const user = userEvent.setup();

        render(<MessageComposer channelName="#general" onSend={onSend} />);

        await user.type(screen.getByRole('textbox'), 'Clicked');
        await user.click(screen.getByRole('button', { name: 'Send message' }));

        expect(onSend).toHaveBeenCalledWith('Clicked', []);
    });

    it('disables the send button while empty', () => {
        render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

        expect(
            screen.getByRole('button', { name: 'Send message' }),
        ).toBeDisabled();
    });

    it('explains itself instead of rendering a box that cannot be used', () => {
        render(
            <MessageComposer
                channelName="#announcements"
                disabled
                disabledReason="Only workspace admins can post here."
                onSend={vi.fn()}
            />,
        );

        // A disabled textarea gives no reason; a sentence does.
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(
            screen.getByText('Only workspace admins can post here.'),
        ).toBeInTheDocument();
    });

    it('labels the input with the channel it posts to', () => {
        render(<MessageComposer channelName="#engineering" onSend={vi.fn()} />);

        // With several composers on screen over a session, "Message" alone
        // would not tell a screen reader user where the text is going.
        expect(
            screen.getByRole('textbox', { name: 'Message #engineering' }),
        ).toBeInTheDocument();
    });

    /** The formatting row renders rich text and sends Snack-compatible source. */
    describe('formatting', () => {
        it('renders selected text with the formatting the button promises', async () => {
            const user = userEvent.setup();

            render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

            const box = screen.getByRole('textbox');
            await user.type(box, 'make me bold');
            selectText(box, 8, 12);

            await user.click(screen.getByRole('button', { name: 'Bold' }));

            expect(box).toHaveTextContent('make me bold');
            expect(box.querySelector('strong')).toHaveTextContent('bold');
        });

        it('keeps the selection so a second press undoes the first', async () => {
            const user = userEvent.setup();

            render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

            const box = screen.getByRole('textbox');
            await user.type(box, 'word');
            selectText(box, 0, 4);

            await user.click(screen.getByRole('button', { name: 'Italic' }));
            await user.click(screen.getByRole('button', { name: 'Italic' }));

            expect(box).toHaveTextContent('word');
            expect(box.querySelector('em')).not.toBeInTheDocument();
        });

        it('marks the line the caret is on for a list', async () => {
            const user = userEvent.setup();

            render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

            const box = screen.getByRole('textbox');
            await user.type(box, 'milk');
            await user.click(
                screen.getByRole('button', { name: 'Bulleted list' }),
            );

            expect(within(box).getByRole('list')).toHaveTextContent('milk');
        });

        it('uses Shift+Enter to add another rich list item', async () => {
            const onSend = vi.fn();
            const user = userEvent.setup();

            render(<MessageComposer channelName="#general" onSend={onSend} />);

            const box = screen.getByRole('textbox');
            await user.type(box, 'milk');
            await user.click(
                screen.getByRole('button', { name: 'Bulleted list' }),
            );
            await user.keyboard('{Shift>}{Enter}{/Shift}bread');
            await user.click(
                screen.getByRole('button', { name: 'Send message' }),
            );

            expect(onSend).toHaveBeenCalledWith('- milk\n- bread', []);
        });

        it('sends the markers as part of the body', async () => {
            const onSend = vi.fn();
            const user = userEvent.setup();

            render(<MessageComposer channelName="#general" onSend={onSend} />);

            const box = screen.getByRole('textbox');
            await user.type(box, 'shipped');
            selectText(box, 0, 7);

            await user.click(screen.getByRole('button', { name: 'Bold' }));
            await user.type(box, '{Enter}');

            expect(onSend).toHaveBeenCalledWith('**shipped**', []);
        });

        it('formats from the keyboard as well as the toolbar', async () => {
            const user = userEvent.setup();

            render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

            const box = screen.getByRole('textbox');
            await user.type(box, 'word');
            selectText(box, 0, 4);

            await user.keyboard('{Control>}b{/Control}');

            expect(box.querySelector('strong')).toHaveTextContent('word');
        });

        it('offers no button for syntax the grammar does not have', () => {
            render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

            // A message body has no underline, so a button for it could only
            // write characters that render as themselves.
            expect(
                screen.queryByRole('button', { name: 'Underline' }),
            ).not.toBeInTheDocument();
        });

        it('hides and restores the row', async () => {
            const user = userEvent.setup();

            render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

            await user.click(
                screen.getByRole('button', { name: 'Hide formatting' }),
            );

            expect(
                screen.queryByRole('button', { name: 'Bold' }),
            ).not.toBeInTheDocument();

            await user.click(
                screen.getByRole('button', { name: 'Show formatting' }),
            );

            expect(
                screen.getByRole('button', { name: 'Bold' }),
            ).toBeInTheDocument();
        });

        it('opens a handle from the mention button', async () => {
            const user = userEvent.setup();

            render(
                <MessageComposer
                    channelName="#general"
                    mentionSource={{ kind: 'local', people: [] }}
                    onSend={vi.fn()}
                />,
            );

            const box = screen.getByRole('textbox');
            await user.type(box, 'ask');
            await user.click(
                screen.getByRole('button', { name: 'Mention someone' }),
            );

            // The space matters: "ask@ada" is not a mention to the tokenizer.
            expect(box).toHaveTextContent('ask @');
        });

        it('replaces a typed mention query and sends the selected handle', async () => {
            const onSend = vi.fn();
            const user = userEvent.setup();

            render(
                <MessageComposer
                    channelName="#general"
                    mentionSource={{ kind: 'local', people: [bo] }}
                    onSend={onSend}
                />,
            );

            const box = screen.getByRole('textbox');
            await user.type(box, 'ask @b');
            await user.click(screen.getByRole('option', { name: /Bo Peep/ }));
            await user.type(box, 'today{Enter}');

            expect(onSend).toHaveBeenCalledWith('ask @bo today', []);
        });

        it('offers no mention button where there is nobody to mention', () => {
            render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

            expect(
                screen.queryByRole('button', { name: 'Mention someone' }),
            ).not.toBeInTheDocument();
        });

        it('offers no formatting where posting is refused', () => {
            render(
                <MessageComposer
                    channelName="#announcements"
                    disabled
                    onSend={vi.fn()}
                />,
            );

            expect(
                screen.queryByRole('button', { name: 'Bold' }),
            ).not.toBeInTheDocument();
        });
    });

    /**
     * Attachments are opt-in: without a workspace to upload to there is no
     * endpoint to talk to, so the control must not be offered at all rather
     * than offered and broken.
     */
    describe('attachments', () => {
        it('offers no paperclip without somewhere to upload to', () => {
            render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

            expect(
                screen.queryByRole('button', { name: 'Attach files' }),
            ).not.toBeInTheDocument();
        });

        it('offers a paperclip once given a workspace', () => {
            render(
                <MessageComposer
                    channelName="#general"
                    workspaceSlug="acme"
                    onSend={vi.fn()}
                />,
            );

            expect(
                screen.getByRole('button', { name: 'Attach files' }),
            ).toBeInTheDocument();
        });

        it('hides the paperclip where posting is refused', () => {
            render(
                <MessageComposer
                    channelName="#announcements"
                    workspaceSlug="acme"
                    disabled
                    onSend={vi.fn()}
                />,
            );

            expect(
                screen.queryByRole('button', { name: 'Attach files' }),
            ).not.toBeInTheDocument();
        });

        it('mentions dropping files in the hint', () => {
            render(
                <MessageComposer
                    channelName="#general"
                    workspaceSlug="acme"
                    onSend={vi.fn()}
                />,
            );

            // Nobody discovers drag-and-drop by guessing.
            expect(screen.getByText(/drop files here/)).toBeInTheDocument();
        });
    });
});

function selectText(element: HTMLElement, start: number, end: number): void {
    const node = element.querySelector('p')?.firstChild;

    if (!(node instanceof Text)) {
        throw new Error('Expected a plain paragraph text node in the editor.');
    }

    const range = document.createRange();
    range.setStart(node, start);
    range.setEnd(node, end);

    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
    fireEvent(document, new Event('selectionchange'));
}
