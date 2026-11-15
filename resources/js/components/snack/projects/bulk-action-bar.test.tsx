import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { BulkActionBar } from '@/components/snack/projects/bulk-action-bar';
import type { ProjectMembership, SnackUser, Sprint } from '@/types';

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

function sprint(id: number, name: string, state: Sprint['state']): Sprint {
    return {
        id,
        name,
        goal: null,
        state,
        is_overdue: false,
        starts_at: null,
        ends_at: null,
        started_at: null,
        completed_at: null,
        position: id,
    };
}

const SPRINTS = [
    sprint(1, 'Sprint 7', 'active'),
    sprint(2, 'Sprint 8', 'planned'),
    sprint(3, 'Sprint 6', 'completed'),
];

const MEMBERS: ProjectMembership[] = [
    { id: 1, user: me, role: 'lead', joined_at: null },
    { id: 2, user: ada, role: 'member', joined_at: null },
];

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

function renderBar(
    onApply = vi.fn().mockResolvedValue({ skipped: [] }),
    count = 3,
) {
    const onClear = vi.fn();

    render(
        <BulkActionBar
            count={count}
            sprints={SPRINTS}
            members={MEMBERS}
            agentsEnabled
            onApply={onApply}
            onClear={onClear}
        />,
    );

    return { onApply, onClear };
}

describe('BulkActionBar', () => {
    it('draws nothing while nothing is ticked', () => {
        const { container } = render(
            <BulkActionBar
                count={0}
                sprints={SPRINTS}
                members={MEMBERS}
                agentsEnabled
                onApply={vi.fn()}
                onClear={vi.fn()}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('moves the selection to an open sprint, never a finished one, then clears it', async () => {
        const { onApply, onClear } = renderBar();

        expect(screen.getByText('3 selected')).toBeInTheDocument();

        open(screen.getByRole('button', { name: /Move to/ }));
        expect(
            screen.queryByRole('menuitem', { name: /Sprint 6/ }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('menuitem', { name: /Sprint 8/ }));

        expect(onApply).toHaveBeenCalledWith({ sprint_id: 2 });
        await waitFor(() => expect(onClear).toHaveBeenCalled());
    });

    it('sends the selection back to the backlog', () => {
        const { onApply } = renderBar();

        open(screen.getByRole('button', { name: /Move to/ }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Backlog' }));

        expect(onApply).toHaveBeenCalledWith({ in_backlog: true });
    });

    it('hands the selection to your own agent, or asks for somebody else’s', () => {
        const { onApply } = renderBar();

        open(screen.getByRole('button', { name: /Assign to agent/ }));
        fireEvent.click(
            screen.getByRole('menuitem', { name: /You — starts now/ }),
        );
        expect(onApply).toHaveBeenLastCalledWith({
            agent: 'assign',
            assignee_id: 1,
        });

        open(screen.getByRole('button', { name: /Assign to agent/ }));
        fireEvent.click(screen.getByRole('menuitem', { name: /Ada Lovelace/ }));
        expect(onApply).toHaveBeenLastCalledWith({
            agent: 'assign',
            assignee_id: 2,
        });
    });

    it('offers no agent when the project has none', () => {
        render(
            <BulkActionBar
                count={2}
                sprints={SPRINTS}
                members={MEMBERS}
                agentsEnabled={false}
                onApply={vi.fn()}
                onClear={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /Assign to agent/ }),
        ).not.toBeInTheDocument();
    });

    it('says which cards it left alone because they already had an agent', async () => {
        renderBar(vi.fn().mockResolvedValue({ skipped: ['PCA-4', 'PCA-9'] }));

        open(screen.getByRole('button', { name: /Assign to agent/ }));
        fireEvent.click(
            screen.getByRole('menuitem', { name: /You — starts now/ }),
        );

        expect(await screen.findByRole('status')).toHaveTextContent(
            'PCA-4 and PCA-9 already had an agent, so they were left as they were.',
        );
    });

    it('shows a refusal and keeps the selection so another choice can be made', async () => {
        const { onClear } = renderBar(
            vi.fn().mockResolvedValue({ error: 'That sprint is finished.' }),
        );

        open(screen.getByRole('button', { name: /Move to/ }));
        fireEvent.click(screen.getByRole('menuitem', { name: /Sprint 8/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'That sprint is finished.',
        );
        expect(onClear).not.toHaveBeenCalled();
    });
});
