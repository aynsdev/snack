import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DevelopmentSection } from '@/components/snack/projects/development-section';
import type { Task } from '@/types';

const writeText = vi.fn().mockResolvedValue(undefined);

function makeTask(overrides: Partial<Task> = {}): Task {
    return {
        id: 1,
        number: 1499,
        reference: 'PWA-1499',
        title: 'Cancel a ride',
        description: null,
        type: 'story',
        priority: 'medium',
        branch_name: 'feature/PWA-1499-cancel-a-ride',
        project_id: 1,
        board_column_id: 1,
        sprint_id: null,
        position: 100,
        story_points: null,
        due_on: null,
        completed_at: null,
        is_complete: false,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('DevelopmentSection', () => {
    beforeEach(() => {
        writeText.mockClear();
        Object.assign(navigator, { clipboard: { writeText } });
    });

    it('shows the branch name in a field somebody can select by hand', () => {
        render(<DevelopmentSection task={makeTask()} />);

        const field = screen.getByLabelText('Branch name');

        // Readable and selectable even where the clipboard API is missing —
        // this application is served over plain HTTP in development.
        expect(field).toHaveValue('feature/PWA-1499-cancel-a-ride');
        expect(field).toHaveAttribute('readonly');
    });

    it('copies the branch, the command, and a commit message carrying the reference', async () => {
        render(<DevelopmentSection task={makeTask()} />);

        fireEvent.click(screen.getByText('Copy branch name'));
        expect(writeText).toHaveBeenCalledWith(
            'feature/PWA-1499-cancel-a-ride',
        );

        fireEvent.click(screen.getByText('Copy checkout command'));
        expect(writeText).toHaveBeenCalledWith(
            'git checkout -b feature/PWA-1499-cancel-a-ride',
        );

        fireEvent.click(screen.getByText('Copy commit message'));
        expect(writeText).toHaveBeenCalledWith('PWA-1499 Cancel a ride');
    });

    it('confirms a copy that landed', async () => {
        render(<DevelopmentSection task={makeTask()} />);

        fireEvent.click(screen.getByText('Copy branch name'));

        expect(await screen.findByText('Copied')).toBeInTheDocument();
    });

    it('still copies where the clipboard API is missing', async () => {
        // Which is this application's own development environment: plain HTTP
        // is an insecure origin, and `navigator.clipboard` is undefined there.
        Object.assign(navigator, { clipboard: undefined });

        const execCommand = vi.fn().mockReturnValue(true);
        let copiedText: string | null = null;

        Object.assign(document, {
            execCommand: (command: string) => {
                // What the browser would copy: the selected carrier field,
                // which only exists for the duration of this call.
                copiedText =
                    document.querySelector('textarea')?.value ?? null;

                return execCommand(command);
            },
        });

        render(<DevelopmentSection task={makeTask()} />);

        fireEvent.click(screen.getByText('Copy checkout command'));

        expect(execCommand).toHaveBeenCalledWith('copy');
        expect(copiedText).toBe(
            'git checkout -b feature/PWA-1499-cancel-a-ride',
        );
        expect(await screen.findByText('Copied')).toBeInTheDocument();

        // The carrier field is a means, not a leftover.
        expect(document.querySelectorAll('textarea')).toHaveLength(0);
    });
});
