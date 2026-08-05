import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TaskCard } from '@/components/snack/projects/task-card';
import type { CardFields, Task } from '@/types';

const ALL_FIELDS: CardFields = {
    type: true,
    key: true,
    priority: true,
    assignee: true,
    labels: true,
    story_points: true,
    due_on: true,
    epic: true,
};

function makeTask(overrides: Partial<Task> = {}): Task {
    return {
        id: 1,
        number: 1499,
        reference: 'PWA-1499',
        title: 'Xendit tokenization api',
        description: null,
        type: 'bug',
        priority: 'high',
        project_id: 1,
        board_column_id: 1,
        sprint_id: null,
        position: 100,
        story_points: 5,
        due_on: null,
        completed_at: null,
        is_complete: false,
        assignee: {
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
        labels: [{ id: 1, name: 'needs design', color: 'rose' }],
        epic: { id: 9, title: 'Payments', reference: 'PWA-900' },
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('TaskCard', () => {
    it('draws every field the project asked for', () => {
        render(
            <TaskCard task={makeTask()} fields={ALL_FIELDS} onOpen={vi.fn()} />,
        );

        expect(screen.getByText('Xendit tokenization api')).toBeInTheDocument();
        expect(screen.getByText('PWA-1499')).toBeInTheDocument();
        expect(screen.getByText('needs design')).toBeInTheDocument();
        expect(screen.getByText('Payments')).toBeInTheDocument();
        expect(screen.getByText('5')).toBeInTheDocument();
        expect(screen.getByText('Bug')).toBeInTheDocument();
        expect(screen.getByText('High priority')).toBeInTheDocument();
    });

    it('draws only the title when the project has turned everything else off', () => {
        render(
            <TaskCard
                task={makeTask()}
                fields={{
                    type: false,
                    key: false,
                    priority: false,
                    assignee: false,
                    labels: false,
                    story_points: false,
                    due_on: false,
                    epic: false,
                }}
                onOpen={vi.fn()}
            />,
        );

        // A team that does not estimate should not look at an empty points slot
        // on every card. The title is never optional — a card without one is
        // not a card.
        expect(screen.getByText('Xendit tokenization api')).toBeInTheDocument();
        expect(screen.queryByText('PWA-1499')).toBeNull();
        expect(screen.queryByText('needs design')).toBeNull();
        expect(screen.queryByText('5')).toBeNull();
    });

    it('says when nothing is assigned rather than leaving a gap', () => {
        render(
            <TaskCard
                task={makeTask({ assignee: null })}
                fields={ALL_FIELDS}
                onOpen={vi.fn()}
            />,
        );

        // "Nobody has this" is information — it is what somebody scanning for
        // work to pick up is looking for.
        expect(screen.getByTitle('Unassigned')).toBeInTheDocument();
    });

    it('is only draggable where the viewer may actually move it', () => {
        const { rerender } = render(
            <TaskCard task={makeTask()} fields={ALL_FIELDS} onOpen={vi.fn()} />,
        );

        expect(screen.getByRole('article')).not.toHaveAttribute(
            'draggable',
            'true',
        );

        rerender(
            <TaskCard
                task={makeTask()}
                fields={ALL_FIELDS}
                draggable
                onOpen={vi.fn()}
            />,
        );

        expect(screen.getByRole('article')).toHaveAttribute(
            'draggable',
            'true',
        );
    });
});
