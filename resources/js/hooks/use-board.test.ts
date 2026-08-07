import { describe, expect, it } from 'vitest';
import {
    dropNeighbours,
    filterTasks,
    NO_BOARD_FILTERS,
    tasksInColumn,
} from '@/hooks/use-board';
import type { Task } from '@/types';

function makeTask(overrides: Partial<Task> & { id: number }): Task {
    return {
        number: overrides.id,
        reference: `PWA-${overrides.id}`,
        title: `Task ${overrides.id}`,
        description: null,
        type: 'story',
        priority: 'medium',
        branch_name: `feature/PWA-${overrides.id}-task`,
        project_id: 1,
        board_column_id: 1,
        sprint_id: null,
        position: 0,
        story_points: null,
        due_on: null,
        completed_at: null,
        is_complete: false,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('tasksInColumn', () => {
    it('orders a column by position, breaking ties on id', () => {
        const tasks = [
            makeTask({ id: 3, position: 200 }),
            makeTask({ id: 1, position: 100 }),
            // Two rows can share a position while a rebalance is in flight; the
            // server breaks the tie on id, so this has to as well or the board
            // and the reload disagree about the order.
            makeTask({ id: 2, position: 100 }),
        ];

        expect(tasksInColumn(tasks, 1).map((task) => task.id)).toEqual([
            1, 2, 3,
        ]);
    });

    it('leaves out the cards belonging to other columns', () => {
        const tasks = [
            makeTask({ id: 1, board_column_id: 1 }),
            makeTask({ id: 2, board_column_id: 2 }),
        ];

        expect(tasksInColumn(tasks, 2).map((task) => task.id)).toEqual([2]);
    });
});

describe('dropNeighbours', () => {
    const column = [
        makeTask({ id: 1, position: 100 }),
        makeTask({ id: 2, position: 200 }),
        makeTask({ id: 3, position: 300 }),
    ];

    it('names the cards either side of the slot', () => {
        expect(dropNeighbours(column, 99, 1)).toEqual({
            before_task_id: 1,
            after_task_id: 2,
        });
    });

    it('reports nothing above at the top and nothing below at the end', () => {
        expect(dropNeighbours(column, 99, 0)).toEqual({
            before_task_id: null,
            after_task_id: 1,
        });

        expect(dropNeighbours(column, 99, 3)).toEqual({
            before_task_id: 3,
            after_task_id: null,
        });
    });

    it('ignores the card being dragged when it is already in the column', () => {
        /*
         * The index is a slot in the column *after* the dragged card has been
         * taken out of it. Counting the card against itself is what makes a
         * drag downwards land one place short of where it was dropped.
         */
        expect(dropNeighbours(column, 1, 2)).toEqual({
            before_task_id: 3,
            after_task_id: null,
        });
    });
});

describe('filterTasks', () => {
    const now = new Date('2026-08-07T12:00:00Z').getTime();

    const ada = {
        id: 7,
        name: 'Ada Lovelace',
        username: 'ada',
        initials: 'AL',
        avatar_url: null,
        title: null,
        department: null,
        presence_status: 'online' as const,
        status_emoji: null,
        status_message: null,
    };

    const board = [
        makeTask({ id: 1, title: 'Cancel a ride', assignee: ada, type: 'bug' }),
        makeTask({
            id: 2,
            title: 'Driver payouts',
            release: { id: 4, name: '1.5.0' },
            updated_at: '2026-08-07T11:00:00Z',
        }),
        makeTask({ id: 3, title: 'Retry webhooks' }),
    ];

    it('shows the whole board when nothing is set', () => {
        expect(filterTasks(board, NO_BOARD_FILTERS, 7, now)).toHaveLength(3);
    });

    it('matches the reference as well as the title', () => {
        // People paste both — "PWA-2" out of a channel, "payouts" from memory.
        expect(
            filterTasks(
                board,
                { ...NO_BOARD_FILTERS, search: 'pwa-2' },
                null,
                now,
            ).map((task) => task.id),
        ).toEqual([2]);

        expect(
            filterTasks(
                board,
                { ...NO_BOARD_FILTERS, search: 'PAYOUTS' },
                null,
                now,
            ).map((task) => task.id),
        ).toEqual([2]);
    });

    it('treats unassigned as a choice like any other', () => {
        expect(
            filterTasks(
                board,
                { ...NO_BOARD_FILTERS, assignees: ['unassigned'] },
                null,
                now,
            ).map((task) => task.id),
        ).toEqual([2, 3]);

        expect(
            filterTasks(
                board,
                { ...NO_BOARD_FILTERS, assignees: [7, 'unassigned'] },
                null,
                now,
            ),
        ).toHaveLength(3);
    });

    it('separates work with no version from work in one', () => {
        expect(
            filterTasks(
                board,
                { ...NO_BOARD_FILTERS, release: 4 },
                null,
                now,
            ).map((task) => task.id),
        ).toEqual([2]);

        expect(
            filterTasks(
                board,
                { ...NO_BOARD_FILTERS, release: 'none' },
                null,
                now,
            ).map((task) => task.id),
        ).toEqual([1, 3]);
    });

    it('combines every clause with AND', () => {
        expect(
            filterTasks(
                board,
                { ...NO_BOARD_FILTERS, type: 'bug', onlyMine: true },
                7,
                now,
            ).map((task) => task.id),
        ).toEqual([1]);

        // Ada's card is a bug, so asking for her stories leaves nothing.
        expect(
            filterTasks(
                board,
                { ...NO_BOARD_FILTERS, type: 'story', onlyMine: true },
                7,
                now,
            ),
        ).toHaveLength(0);
    });

    it('counts a card as recent only if it was touched inside the day', () => {
        expect(
            filterTasks(
                board,
                { ...NO_BOARD_FILTERS, recentlyUpdated: true },
                null,
                now,
            ).map((task) => task.id),
        ).toEqual([2]);
    });
});
