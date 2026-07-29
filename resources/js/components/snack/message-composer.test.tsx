import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { MessageComposer } from '@/components/snack/message-composer';

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

        expect(onSend).toHaveBeenCalledWith('Hello');
    });

    it('inserts a newline on Shift+Enter instead of sending', async () => {
        const onSend = vi.fn();
        const user = userEvent.setup();

        render(<MessageComposer channelName="#general" onSend={onSend} />);

        const box = screen.getByRole('textbox');
        await user.type(box, 'first{Shift>}{Enter}{/Shift}second');

        expect(onSend).not.toHaveBeenCalled();
        expect(box).toHaveValue('first\nsecond');
    });

    it('clears after sending', async () => {
        const user = userEvent.setup();

        render(<MessageComposer channelName="#general" onSend={vi.fn()} />);

        const box = screen.getByRole('textbox');
        await user.type(box, 'Hello{Enter}');

        expect(box).toHaveValue('');
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

        expect(onSend).toHaveBeenCalledWith('padded');
    });

    it('sends from the send button', async () => {
        const onSend = vi.fn();
        const user = userEvent.setup();

        render(<MessageComposer channelName="#general" onSend={onSend} />);

        await user.type(screen.getByRole('textbox'), 'Clicked');
        await user.click(screen.getByRole('button', { name: 'Send message' }));

        expect(onSend).toHaveBeenCalledWith('Clicked');
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
});
