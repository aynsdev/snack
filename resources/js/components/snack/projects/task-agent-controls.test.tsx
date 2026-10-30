import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TaskAgentControls } from '@/components/snack/projects/task-agent-controls';
import type { SnackUser, Task, TaskLabel } from '@/types';

function makeUser(overrides: Partial<SnackUser> = {}): SnackUser {
    return {
        id: 1,
        name: 'Me Myself',
        username: 'me',
        initials: 'MM',
        avatar_url: null,
        title: null,
        department: null,
        presence_status: 'online',
        status_emoji: null,
        status_message: null,
        ...overrides,
    };
}

const me = makeUser();
const ada = makeUser({ id: 2, name: 'Ada Lovelace', username: 'ada' });

vi.mock('@/hooks/use-workspace', () => ({ useCurrentUser: () => me }));

function label(id: number, name: string): TaskLabel {
    return { id, name, color: 'violet' };
}

function makeTask(overrides: Partial<Task> = {}): Task {
    return {
        id: 1,
        number: 3,
        reference: 'TES-3',
        title: 'Fix the login',
        description: null,
        type: 'task',
        priority: 'medium',
        branch_name: 'feature/TES-3-fix-the-login',
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

function renderControls(task: Task) {
    const onSave = vi.fn().mockResolvedValue(undefined);

    render(<TaskAgentControls task={task} members={[]} onSave={onSave} />);

    return onSave;
}

describe('TaskAgentControls', () => {
    it('assigns the existing assignee without asking whose agent', () => {
        const onSave = renderControls(makeTask({ assignee: ada }));

        fireEvent.click(
            screen.getByRole('button', { name: /assign to agent/i }),
        );

        expect(onSave).toHaveBeenCalledWith({ agent: 'assign' });
    });

    it('lets the assignee start a requested agent', () => {
        const onSave = renderControls(
            makeTask({ assignee: me, labels: [label(9, 'agent-requested')] }),
        );

        fireEvent.click(screen.getByRole('button', { name: /start agent/i }));

        expect(onSave).toHaveBeenCalledWith({ agent: 'start' });
    });

    it('tells everybody else who has to start it', () => {
        renderControls(
            makeTask({ assignee: ada, labels: [label(9, 'agent-requested')] }),
        );

        expect(
            screen.getByText(/agent requested — waiting for ada lovelace/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /start agent/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /cancel request/i }),
        ).toBeInTheDocument();
    });

    it('stops an active agent', () => {
        const onSave = renderControls(
            makeTask({ assignee: me, labels: [label(8, 'agent')] }),
        );

        expect(screen.getByText(/agent working/i)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /^stop$/i }));

        expect(onSave).toHaveBeenCalledWith({ agent: 'stop' });
    });

    it('offers the member picker when nobody is assigned', () => {
        renderControls(makeTask());

        expect(screen.getByText(/no agent/i)).toBeInTheDocument();
        expect(
            screen.getByRole('combobox', { name: /assign to agent/i }),
        ).toBeInTheDocument();
    });

    it('offers the assignee a requested agent to start, not to cancel', () => {
        renderControls(
            makeTask({ assignee: me, labels: [label(9, 'agent-requested')] }),
        );

        expect(
            screen.queryByRole('button', { name: /cancel request/i }),
        ).not.toBeInTheDocument();
    });
});
