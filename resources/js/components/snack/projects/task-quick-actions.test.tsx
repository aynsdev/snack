import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    TaskAssigneeMenu,
    TaskPriorityMenu,
    TaskRowMenu,
    TaskStatusMenu,
    TaskTypeMenu,
} from '@/components/snack/projects/task-quick-actions';
import type {
    BoardColumn,
    ProjectMembership,
    SnackUser,
    Sprint,
    Task,
    TaskLabel,
} from '@/types';

function makeUser(overrides: Partial<SnackUser> = {}): SnackUser {
    return {
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
        ...overrides,
    };
}

function makeTask(overrides: Partial<Task> = {}): Task {
    return {
        id: 1,
        number: 3,
        reference: 'TES-3',
        title: 'Filed during a sprint',
        description: null,
        type: 'story',
        priority: 'medium',
        branch_name: 'feature/TES-3-filed-during-a-sprint',
        project_id: 1,
        board_column_id: 1,
        sprint_id: null,
        position: 100,
        story_points: null,
        due_on: null,
        completed_at: null,
        is_complete: false,
        assignee: null,
        labels: [],
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

const COLUMNS: BoardColumn[] = [
    {
        id: 1,
        name: 'To do',
        category: 'unstarted',
        position: 1,
        wip_limit: null,
    },
    { id: 2, name: 'Doing', category: 'started', position: 2, wip_limit: null },
];

const MEMBERS: ProjectMembership[] = [
    { id: 10, user: makeUser(), role: 'member', joined_at: null },
];

const SPRINTS: Sprint[] = [
    {
        id: 5,
        name: 'Sprint 1',
        goal: null,
        state: 'active',
        is_overdue: false,
        starts_at: null,
        ends_at: null,
        started_at: null,
        completed_at: null,
        position: 1,
    },
    {
        id: 6,
        name: 'Sprint 0',
        goal: null,
        state: 'completed',
        is_overdue: false,
        starts_at: null,
        ends_at: null,
        started_at: null,
        completed_at: '2026-08-01T00:00:00Z',
        position: 0,
    },
];

const LABELS: TaskLabel[] = [{ id: 3, name: 'needs design', color: 'rose' }];

/** Radix opens on pointerdown, which jsdom does not synthesise from a click. */
function open(trigger: HTMLElement) {
    fireEvent.pointerDown(
        trigger,
        new PointerEvent('pointerdown', {
            bubbles: true,
            ctrlKey: false,
            button: 0,
        }),
    );
}

describe('task quick actions', () => {
    it('changes the type from the row', async () => {
        const onChange = vi.fn();

        render(
            <TaskTypeMenu
                task={makeTask()}
                disabled={false}
                onChange={onChange}
            />,
        );

        open(screen.getByLabelText('Change type — currently Story'));
        // By role, not by text: the glyph beside each item carries a screen
        // reader label of its own, which aria-hidden keeps out of the name.
        fireEvent.click(await screen.findByRole('menuitem', { name: 'Bug' }));

        expect(onChange).toHaveBeenCalledWith('bug');
    });

    it('changes the priority from the row', async () => {
        const onChange = vi.fn();

        render(
            <TaskPriorityMenu
                task={makeTask()}
                disabled={false}
                onChange={onChange}
            />,
        );

        open(screen.getByLabelText('Change priority — currently Medium'));
        fireEvent.click(await screen.findByText('Highest'));

        expect(onChange).toHaveBeenCalledWith('highest');
    });

    it('moves the card to the column picked, and does nothing for the one it is in', async () => {
        const onChange = vi.fn();

        render(
            <TaskStatusMenu
                task={makeTask()}
                columns={COLUMNS}
                disabled={false}
                onChange={onChange}
            />,
        );

        const trigger = screen.getByLabelText(
            'Change status — currently To do',
        );

        open(trigger);
        // The column it already sits in: a pick that would cost a request and
        // change nothing.
        fireEvent.click(await screen.findByRole('menuitem', { name: 'To do' }));
        expect(onChange).not.toHaveBeenCalled();

        open(trigger);
        fireEvent.click(await screen.findByRole('menuitem', { name: 'Doing' }));
        expect(onChange).toHaveBeenCalledWith(2);
    });

    it('assigns and unassigns without opening the card', async () => {
        const onChange = vi.fn();

        const { rerender } = render(
            <TaskAssigneeMenu
                task={makeTask()}
                members={MEMBERS}
                disabled={false}
                onChange={onChange}
            />,
        );

        open(screen.getByLabelText('Assign this task'));
        fireEvent.click(await screen.findByText('Ada Lovelace'));

        expect(onChange).toHaveBeenCalledWith(2);

        rerender(
            <TaskAssigneeMenu
                task={makeTask({ assignee: makeUser() })}
                members={MEMBERS}
                disabled={false}
                onChange={onChange}
            />,
        );

        open(screen.getByLabelText('Change assignee — currently Ada Lovelace'));
        fireEvent.click(await screen.findByText('Unassigned'));

        expect(onChange).toHaveBeenLastCalledWith(null);
    });

    it('gives somebody who cannot contribute the glyph and no control', () => {
        render(<TaskTypeMenu task={makeTask()} disabled onChange={vi.fn()} />);

        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.getByTitle('Story')).toBeInTheDocument();
    });
});

describe('TaskRowMenu', () => {
    function renderMenu(task: Task, handlers: Record<string, unknown> = {}) {
        const props = {
            onUpdate: vi.fn(),
            onMove: vi.fn(),
            onDelete: vi.fn(),
            ...handlers,
        };

        render(
            <TaskRowMenu
                task={task}
                sprints={SPRINTS}
                labels={LABELS}
                canContribute
                canDelete
                onUpdate={props.onUpdate as never}
                onMove={props.onMove as never}
                onDelete={props.onDelete as never}
            />,
        );

        open(screen.getByText('More actions for TES-3').closest('button')!);

        return props;
    }

    it('commits the card to a sprint', async () => {
        const { onMove } = renderMenu(makeTask());

        fireEvent.click(await screen.findByText('Sprint 1'));

        expect(onMove).toHaveBeenCalledWith({
            sprint_id: 5,
            in_backlog: false,
        });
    });

    it('offers no completed sprint to move into', async () => {
        renderMenu(makeTask());

        await screen.findByText('Sprint 1');

        expect(screen.queryByText('Sprint 0')).toBeNull();
    });

    it('sets an estimate', async () => {
        const { onUpdate } = renderMenu(makeTask());

        fireEvent.click(await screen.findByRole('button', { name: '5' }));

        expect(onUpdate).toHaveBeenCalledWith({ story_points: 5 });
    });

    it('puts a label on the card', async () => {
        const { onUpdate } = renderMenu(makeTask());

        fireEvent.click(await screen.findByText('needs design'));

        expect(onUpdate).toHaveBeenCalledWith({ label_ids: [3] });
    });

    it('takes the label off a card that already wears it', async () => {
        const { onUpdate } = renderMenu(
            makeTask({
                labels: [{ id: 3, name: 'needs design', color: 'rose' }],
            }),
        );

        fireEvent.click(await screen.findByText('needs design'));

        expect(onUpdate).toHaveBeenCalledWith({ label_ids: [] });
    });

    it('asks once before deleting', async () => {
        const { onDelete } = renderMenu(makeTask());

        fireEvent.click(await screen.findByText('Delete task'));

        expect(onDelete).not.toHaveBeenCalled();

        // The menu stayed open, armed, so the second click is the one that
        // means it.
        fireEvent.click(await screen.findByText('Really delete TES-3?'));

        expect(onDelete).toHaveBeenCalledTimes(1);
    });
});
