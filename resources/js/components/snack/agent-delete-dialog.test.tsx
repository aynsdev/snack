import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AgentDeleteDialog } from '@/components/snack/agent-delete-dialog';
import type { SnackUser } from '@/types';

const owner: SnackUser = {
    id: 2,
    name: 'Ada Lovelace',
    username: 'ada',
    initials: 'AL',
    avatar_url: null,
    title: null,
    department: null,
    presence_status: 'online',
    status_emoji: null,
    status_message: null,
};

function renderDialog(parentMessageId: number | null) {
    const onConfirm = vi.fn();
    const onOpenChange = vi.fn();

    render(
        <AgentDeleteDialog
            message={{ parent_message_id: parentMessageId, sender: owner }}
            open
            onOpenChange={onOpenChange}
            onConfirm={onConfirm}
        />,
    );

    return { onConfirm, onOpenChange };
}

describe('AgentDeleteDialog', () => {
    it('warns that a deleted thread start is posted again', () => {
        renderDialog(null);

        expect(
            screen.getByText(
                "This message belongs to Ada Lovelace's agent. Deleting the thread start will make the agent re-post it.",
            ),
        ).toBeInTheDocument();
    });

    it('asks generically for any other agent message', () => {
        renderDialog(41);

        expect(screen.getByText('Delete agent message?')).toBeInTheDocument();
    });

    it('deletes only once confirmed', () => {
        const { onConfirm, onOpenChange } = renderDialog(41);

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onConfirm).not.toHaveBeenCalled();
        expect(onOpenChange).toHaveBeenCalledWith(false);

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(onConfirm).toHaveBeenCalledTimes(1);
    });
});
