import { useCallback, useState } from 'react';
import { api } from '@/lib/api';
import { show as taskShow } from '@/routes/tasks';
import type { Task } from '@/types';

/**
 * Which card the task panel shows, on a page that holds a list of cards.
 *
 * Read out of the live list by id, so edits and socket updates redraw the
 * panel. A card that is not in the list — a subtask in the backlog, opened
 * from its parent on the board — is fetched once and held beside it, so the
 * panel can still open it.
 */
export function useOpenTask({
    tasks,
    workspaceSlug,
    initialTask = null,
}: {
    tasks: Task[];
    workspaceSlug: string;
    /** A card to open on arrival, such as one named by `?task=PWA-1499`. */
    initialTask?: Task | null;
}) {
    const [openTaskId, setOpenTaskId] = useState<number | null>(
        initialTask?.id ?? null,
    );
    const [fetched, setFetched] = useState<Task | null>(initialTask);

    const selected =
        tasks.find((task) => task.id === openTaskId) ??
        (fetched !== null && fetched.id === openTaskId ? fetched : null);

    const open = useCallback(
        async (taskId: number) => {
            if (tasks.some((task) => task.id === taskId)) {
                setOpenTaskId(taskId);

                return;
            }

            const { task } = await api.get<{ task: Task }>(
                taskShow.url({ workspace: workspaceSlug, task: taskId }),
            );

            setFetched(task);
            setOpenTaskId(task.id);
        },
        [tasks, workspaceSlug],
    );

    const close = useCallback(() => setOpenTaskId(null), []);

    return { openTaskId, selected, open, close };
}
