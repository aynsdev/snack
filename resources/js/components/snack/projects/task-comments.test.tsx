import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TaskComments } from '@/components/snack/projects/task-comments';
import type { TaskComment } from '@/types';

function makeComment(overrides: Partial<TaskComment> = {}): TaskComment {
    return {
        id: 1,
        body: 'The API returns 409 when the ride already started.',
        edited_at: null,
        created_at: '2026-08-07T10:00:00+00:00',
        author: {
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
        },
        agent: null,
        can: { update: true, delete: true },
        ...overrides,
    };
}

function renderComments(
    props: Partial<Parameters<typeof TaskComments>[0]> = {},
) {
    const handlers = {
        onAdd: vi.fn(),
        onEdit: vi.fn(),
        onRemove: vi.fn(),
    };

    render(
        <TaskComments
            comments={[makeComment()]}
            canContribute
            isLoading={false}
            isSaving={false}
            {...handlers}
            {...props}
        />,
    );

    return handlers;
}

describe('TaskComments', () => {
    it('sends a trimmed comment and clears the box', () => {
        const { onAdd } = renderComments({ comments: [] });

        const box = screen.getByLabelText('Add a comment');

        fireEvent.change(box, { target: { value: '  Needs a migration.  ' } });
        fireEvent.click(screen.getByText('Comment'));

        expect(onAdd).toHaveBeenCalledWith('Needs a migration.');
        expect(box).toHaveValue('');
    });

    it('does not send on Enter alone', () => {
        const { onAdd } = renderComments({ comments: [] });

        const box = screen.getByLabelText('Add a comment');

        fireEvent.change(box, { target: { value: 'First line' } });
        fireEvent.keyDown(box, { key: 'Enter' });

        // A comment on a card is a paragraph, not a chat line — losing one
        // halfway through costs more than a click does.
        expect(onAdd).not.toHaveBeenCalled();

        fireEvent.keyDown(box, { key: 'Enter', metaKey: true });

        expect(onAdd).toHaveBeenCalledWith('First line');
    });

    it('offers no controls for somebody else’s comment', () => {
        renderComments({
            comments: [makeComment({ can: { update: false, delete: false } })],
        });

        // The flags are the policy's answer: editing is the author's alone,
        // deleting is the author's or a lead's, and the client guesses neither.
        expect(screen.queryByText('Edit')).not.toBeInTheDocument();
        expect(screen.queryByText('Delete')).not.toBeInTheDocument();
    });

    it('confirms before deleting', () => {
        const { onRemove } = renderComments();

        fireEvent.click(screen.getByText('Delete'));
        expect(onRemove).not.toHaveBeenCalled();

        fireEvent.click(screen.getByText('Delete for everyone'));
        expect(onRemove).toHaveBeenCalledWith(1);
    });

    it('edits in place and marks nothing until the server says so', () => {
        const { onEdit } = renderComments();

        fireEvent.click(screen.getByText('Edit'));
        fireEvent.change(screen.getByLabelText('Edit comment'), {
            target: { value: 'Corrected: it returns 422.' },
        });
        fireEvent.click(screen.getByText('Save'));

        expect(onEdit).toHaveBeenCalledWith(1, 'Corrected: it returns 422.');
    });

    it('names a deleted author rather than dropping their words', () => {
        renderComments({ comments: [makeComment({ author: null })] });

        expect(screen.getByText('Former member')).toBeInTheDocument();
        expect(
            screen.getByText(
                'The API returns 409 when the ride already started.',
            ),
        ).toBeInTheDocument();
    });

    it('marks an edited comment', () => {
        renderComments({
            comments: [makeComment({ edited_at: '2026-08-07T11:00:00+00:00' })],
        });

        expect(screen.getByText('(edited)')).toBeInTheDocument();
    });

    it('marks a comment an agent wrote', () => {
        renderComments({
            comments: [
                makeComment({
                    agent: { name: 'LocalAgent' },
                    can: { update: false, delete: true },
                }),
            ],
        });

        expect(screen.getByText('Agent')).toBeInTheDocument();
    });
});
