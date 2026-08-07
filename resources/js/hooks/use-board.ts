import { router } from '@inertiajs/react';
import { useEchoPresence } from '@laravel/echo-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '@/lib/api';
import { channels, events } from '@/lib/echo';
import { store as storeTask } from '@/routes/projects/tasks';
import {
    destroy as destroyTask,
    move as moveTask,
    update as updateTask,
} from '@/routes/tasks';
import type { Task, TaskChangedEvent, TaskType } from '@/types';

/**
 * The cards on one board, and every way they change.
 *
 * # Why the client owns this list
 *
 * A board is not a page that gets replaced — it is a surface several people
 * drag on at once. The server sends the whole set as a prop for the first paint,
 * and after that changes arrive one card at a time over the socket. Re-rendering
 * the page for each of them would throw away the reader's scroll position and
 * any panel they had open, several times a minute, for changes they did not
 * make.
 *
 * # Optimistic, and corrected
 *
 * A drag applies locally the instant it happens, because a card that snaps back
 * for a round trip reads as a failed drop. The server's answer then replaces the
 * card wholesale — same as an arrival over the socket — so the client's guess at
 * the new position is only ever a placeholder for the truth. A failed request
 * puts the card back where it was.
 */
export function useBoard({
    projectId,
    projectSlug,
    workspaceSlug,
    initialTasks,
}: {
    projectId: number;
    projectSlug: string;
    workspaceSlug: string;
    initialTasks: Task[];
}) {
    const [tasks, setTasks] = useState<Task[]>(initialTasks);
    const [error, setError] = useState<string | null>(null);

    /*
     * The props win whenever they change.
     *
     * A navigation between board and backlog, or a reload after the board's
     * shape changed, brings a new set — and the state from the page being left
     * must not survive into it. Keyed on the array identity Inertia hands over,
     * which is a new object on every visit and stable within one.
     *
     * Adjusted during render rather than in an effect. An effect would paint the
     * previous board once before correcting itself, which on a navigation is a
     * visible flash of the wrong project's cards; React re-runs this component
     * immediately instead, before anything reaches the screen.
     */
    const [seed, setSeed] = useState(initialTasks);

    if (seed !== initialTasks) {
        setSeed(initialTasks);
        setTasks(initialTasks);
    }

    /**
     * Replace one card, or drop it. The single writer every path goes through:
     * a socket arrival, a save, and the answer to an optimistic move all mean
     * "this is the card now".
     */
    const apply = useCallback((taskId: number, next: Task | null) => {
        setTasks((current) => {
            const without = current.filter((task) => task.id !== taskId);

            return next === null ? without : [...without, next];
        });
    }, []);

    /*
     * Somebody else moved something.
     *
     * Applied by id rather than merged, so a client that missed an earlier
     * broadcast is corrected by the next one instead of accumulating drift.
     */
    useEchoPresence<TaskChangedEvent>(
        channels.project(projectId),
        events.taskChanged,
        (payload) => apply(payload.task_id, payload.task),
        [projectId],
    );

    /*
     * The board's own shape changed — a column added, a sprint started.
     *
     * A reload rather than a patch: these change which cards belong here at all,
     * and the props are the one description of that. `only` keeps it to the
     * board's own props, so the shell and the sidebar are not rebuilt as well.
     */
    useEchoPresence(
        channels.project(projectId),
        events.boardChanged,
        () => {
            router.reload({
                only: [
                    'columns',
                    'tasks',
                    'sprint',
                    'plannedSprints',
                    'sprints',
                    'backlogCount',
                ],
            });
        },
        [projectId],
    );

    const create = useCallback(
        async (attributes: Record<string, unknown>) => {
            const { task } = await api.post<{ task: Task }>(
                storeTask.url({
                    workspace: workspaceSlug,
                    project: projectSlug,
                }),
                attributes,
            );

            apply(task.id, task);

            return task;
        },
        [apply, projectSlug, workspaceSlug],
    );

    const update = useCallback(
        async (taskId: number, attributes: Record<string, unknown>) => {
            const { task } = await api.patch<{ task: Task }>(
                updateTask.url({ workspace: workspaceSlug, task: taskId }),
                attributes,
            );

            apply(task.id, task);

            return task;
        },
        [apply, workspaceSlug],
    );

    const remove = useCallback(
        async (taskId: number) => {
            apply(taskId, null);

            await api.delete(
                destroyTask.url({ workspace: workspaceSlug, task: taskId }),
            );
        },
        [apply, workspaceSlug],
    );

    /**
     * A drop.
     *
     * The neighbours are sent as ids and never as positions — which numbers the
     * cards around it hold is the server's business, and a client sending them
     * would be sending a copy of a board it painted seconds ago.
     */
    const move = useCallback(
        async (
            task: Task,
            target: {
                board_column_id?: number;
                sprint_id?: number | null;
                in_backlog?: boolean;
                before_task_id?: number | null;
                after_task_id?: number | null;
            },
        ) => {
            const original = task;

            // Optimistic. The position is a guess good enough to sit between the
            // neighbours until the server says where it really went.
            apply(task.id, {
                ...task,
                board_column_id: target.board_column_id ?? task.board_column_id,
                sprint_id:
                    target.in_backlog === true
                        ? null
                        : (target.sprint_id ?? task.sprint_id),
            });

            try {
                const { task: saved } = await api.patch<{ task: Task }>(
                    moveTask.url({ workspace: workspaceSlug, task: task.id }),
                    target,
                );

                apply(saved.id, saved);
            } catch {
                // Put it back. A card left in the column it failed to reach is
                // worse than one that never appeared to move.
                apply(original.id, original);
                setError('That card could not be moved. Try again.');
            }
        },
        [apply, workspaceSlug],
    );

    return { tasks, apply, create, update, remove, move, error, setError };
}

/**
 * The cards of one column, in board order.
 *
 * Sorted here rather than kept sorted in state: {@see useBoard} appends on every
 * change, because a list that reorders itself on each socket arrival would be a
 * second ordering to keep honest. `position` is the server's, and ties break on
 * id — which is what the server's own `ordered()` scope does.
 */
export function tasksInColumn(tasks: Task[], columnId: number): Task[] {
    return tasks
        .filter((task) => task.board_column_id === columnId)
        .sort(
            (left, right) =>
                left.position - right.position || left.id - right.id,
        );
}

/**
 * What the board's filter bar is set to.
 *
 * Empty/`all`/false everywhere means "show the board", which is the state it
 * starts in and the one `hasBoardFilters` reports on.
 */
export type BoardFilters = {
    /** Matched against the title and the reference — people paste both. */
    search: string;
    /** Whose cards to show; `'unassigned'` is a choice like any other. */
    assignees: (number | 'unassigned')[];
    /** A release id, `'none'` for work scheduled into no version, or `'all'`. */
    release: number | 'none' | 'all';
    type: TaskType | 'all';
    /** Jira's quick filters: mine, and touched in the last day. */
    onlyMine: boolean;
    recentlyUpdated: boolean;
};

export const NO_BOARD_FILTERS: BoardFilters = {
    search: '',
    assignees: [],
    release: 'all',
    type: 'all',
    onlyMine: false,
    recentlyUpdated: false,
};

/** A day, which is what "recently updated" means on a board. */
const RECENT_MS = 24 * 60 * 60 * 1000;

export function hasBoardFilters(filters: BoardFilters): boolean {
    return (
        filters.search.trim() !== '' ||
        filters.assignees.length > 0 ||
        filters.release !== 'all' ||
        filters.type !== 'all' ||
        filters.onlyMine ||
        filters.recentlyUpdated
    );
}

/**
 * The cards a filtered board shows.
 *
 * Every clause is an AND and every filter is applied to the cards the client
 * already holds — the board is fully in memory, so narrowing it must not cost a
 * round trip, and a filter that did would make the columns flicker through an
 * empty state on every keystroke.
 *
 * The columns keep their own counts, so a filtered board says how many cards
 * match *in each column* rather than how many exist. That is the honest reading:
 * a number that ignored the filter would describe a board nobody is looking at.
 */
export function filterTasks(
    tasks: Task[],
    filters: BoardFilters,
    viewerId: number | null,
    now: number = Date.now(),
): Task[] {
    const search = filters.search.trim().toLowerCase();

    return tasks.filter((task) => {
        if (
            search !== '' &&
            !task.title.toLowerCase().includes(search) &&
            !task.reference.toLowerCase().includes(search)
        ) {
            return false;
        }

        if (filters.assignees.length > 0) {
            const who = task.assignee?.id ?? 'unassigned';

            if (!filters.assignees.includes(who)) {
                return false;
            }
        }

        if (filters.onlyMine && task.assignee?.id !== viewerId) {
            return false;
        }

        if (filters.release !== 'all') {
            const release = task.release?.id ?? 'none';

            if (release !== filters.release) {
                return false;
            }
        }

        if (filters.type !== 'all' && task.type !== filters.type) {
            return false;
        }

        if (filters.recentlyUpdated) {
            const updated =
                task.updated_at === null
                    ? null
                    : new Date(task.updated_at).getTime();

            if (updated === null || now - updated > RECENT_MS) {
                return false;
            }
        }

        return true;
    });
}

/**
 * Where a drop lands: the ids of the cards it fell between.
 *
 * `index` is the slot in the destination column *after* the dragged card has
 * been taken out of it, which is what makes moving a card down within its own
 * column land where the reader dropped it rather than one place short.
 */
export function dropNeighbours(
    columnTasks: Task[],
    draggedId: number,
    index: number,
): { before_task_id: number | null; after_task_id: number | null } {
    const without = columnTasks.filter((task) => task.id !== draggedId);

    return {
        before_task_id: without[index - 1]?.id ?? null,
        after_task_id: without[index]?.id ?? null,
    };
}

/**
 * A stable ref to the latest value.
 *
 * Used by the drag handlers, which are attached once and would otherwise close
 * over the first render's board for ever.
 */
export function useLatest<T>(value: T) {
    const ref = useRef(value);

    useEffect(() => {
        ref.current = value;
    }, [value]);

    return ref;
}
