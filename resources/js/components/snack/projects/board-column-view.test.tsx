import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BoardColumnView } from '@/components/snack/projects/board-column-view';
import type { BoardColumn, CardFields } from '@/types';

const patch = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (...args: unknown[]) => patch(...args),
        delete: vi.fn(),
    },
}));

function makeColumn(overrides: Partial<BoardColumn> = {}): BoardColumn {
    return {
        id: 7,
        name: 'In progress',
        category: 'started',
        position: 65536,
        wip_limit: null,
        ...overrides,
    };
}

const fields: CardFields = {
    type: true,
    key: true,
    priority: true,
    assignee: true,
    labels: true,
    story_points: false,
    due_on: false,
    epic: true,
};

function renderColumn(canManage: boolean) {
    render(
        <BoardColumnView
            column={makeColumn()}
            tasks={[]}
            fields={fields}
            canContribute
            canManage={canManage}
            workspaceSlug="snack-hq"
            projectSlug="pedicab-web-api"
            draggingId={null}
            onOpenTask={vi.fn()}
            onDragStart={vi.fn()}
            onDragEnd={vi.fn()}
            onDrop={vi.fn()}
            onCreate={vi.fn()}
        />,
    );
}

describe('BoardColumnView', () => {
    beforeEach(() => {
        patch.mockClear();
    });

    it('renames a column from its own header', () => {
        renderColumn(true);

        fireEvent.click(screen.getByTitle('Rename column'));

        const input = screen.getByLabelText('Rename In progress');

        fireEvent.change(input, { target: { value: 'To verify' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(patch).toHaveBeenCalledTimes(1);
        expect(patch.mock.calls[0][1]).toEqual({ name: 'To verify' });
    });

    it('puts the old name back on Escape', () => {
        renderColumn(true);

        fireEvent.click(screen.getByTitle('Rename column'));

        const input = screen.getByLabelText('Rename In progress');

        fireEvent.change(input, { target: { value: 'Oops' } });
        fireEvent.keyDown(input, { key: 'Escape' });

        expect(patch).not.toHaveBeenCalled();
        expect(screen.getByTitle('Rename column')).toHaveTextContent(
            'In progress',
        );
    });

    it('saves nothing when the name is emptied or unchanged', () => {
        renderColumn(true);

        fireEvent.click(screen.getByTitle('Rename column'));

        const input = screen.getByLabelText('Rename In progress');

        // An emptied name is a slip, not an intent — the same rule the card
        // title follows.
        fireEvent.change(input, { target: { value: '   ' } });
        fireEvent.blur(input);

        expect(patch).not.toHaveBeenCalled();
    });

    it('saves a work-in-progress limit typed into the column menu', async () => {
        renderColumn(true);

        // Radix opens its menu on pointerdown, which jsdom does not synthesise
        // from a click.
        const trigger = screen.getByText('Column options').closest('button')!;

        fireEvent.pointerDown(
            trigger,
            new PointerEvent('pointerdown', {
                bubbles: true,
                ctrlKey: false,
                button: 0,
            }),
        );

        const limit = await screen.findByLabelText(
            'Work in progress limit for In progress',
        );

        fireEvent.change(limit, { target: { value: '3' } });
        fireEvent.keyDown(limit, { key: 'Enter' });

        // Closing the menu unmounts the input, so a limit left to `onBlur`
        // would be lost by the gesture that means "done".
        expect(patch).toHaveBeenCalledTimes(1);
        expect(patch.mock.calls[0][1]).toEqual({ wip_limit: 3 });
    });

    it('offers no rename or column menu to somebody who cannot manage', () => {
        renderColumn(false);

        expect(screen.queryByTitle('Rename column')).toBeNull();
        expect(screen.queryByText('Column options')).toBeNull();
        expect(screen.getByRole('heading')).toHaveTextContent('In progress');
    });
});
