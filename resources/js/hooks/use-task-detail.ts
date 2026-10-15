import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useState } from 'react';
import { ApiError, api } from '@/lib/api';
import { show as taskShow } from '@/routes/tasks';
import { store as attachmentStore } from '@/routes/tasks/attachments';
import { destroy as attachmentDestroy } from '@/routes/tasks/attachments';
import {
    destroy as commentDestroy,
    store as commentStore,
    update as commentUpdate,
} from '@/routes/tasks/comments';
import {
    destroy as linkDestroy,
    store as linkStore,
} from '@/routes/tasks/links';
import {
    destroy as relationDestroy,
    store as relationStore,
} from '@/routes/tasks/relations';
import type {
    Attachment,
    Task,
    TaskComment,
    TaskLink,
    TaskRelation,
    TaskRelationType,
} from '@/types';

/**
 * The half of a card that only exists once somebody opens it.
 *
 * The board already holds every card it draws, so the panel renders instantly
 * from what is in memory; the discussion and the files are fetched on open
 * instead, because a board is eighty cards and eighty discussions is a payload
 * nobody reads. That is why this is a query rather than a board prop.
 *
 * Keyed on the task id alone — never on an object the caller rebuilds each
 * render, which is the effect-identity trap that fetches forever and reports
 * nothing.
 *
 * Every mutation writes the server's answer back into the cache rather than
 * refetching: the endpoints return the saved row precisely so the panel can
 * store the truth instead of its own optimistic guess.
 */
export function useTaskDetail({
    workspaceSlug,
    taskId,
}: {
    workspaceSlug: string;
    taskId: number | null;
}) {
    const client = useQueryClient();
    const [error, setError] = useState<string | null>(null);

    const query = useQuery({
        queryKey: ['task-detail', taskId],
        enabled: taskId !== null,
        queryFn: async () => {
            const { task } = await api.get<{ task: Task }>(
                taskShow.url({ workspace: workspaceSlug, task: taskId ?? 0 }),
            );

            return task;
        },
    });

    /**
     * Replace the cached card, so the panel redraws from one source.
     *
     * The key is rebuilt here from the id rather than closed over: a query key
     * is an array literal, new on every render, and depending on the array
     * itself would rebuild this callback — and everything holding it — each time.
     */
    const patch = useCallback(
        (change: (task: Task) => Task) => {
            client.setQueryData<Task>(['task-detail', taskId], (current) =>
                current === undefined ? current : change(current),
            );
        },
        [client, taskId],
    );

    const report = (cause: unknown, fallback: string) => {
        setError(cause instanceof ApiError ? cause.userMessage : fallback);

        throw cause;
    };

    const addComment = useMutation({
        mutationFn: async (body: string) => {
            const { comment } = await api
                .post<{ comment: TaskComment }>(
                    commentStore.url({
                        workspace: workspaceSlug,
                        task: taskId ?? 0,
                    }),
                    { body },
                )
                .catch((cause: unknown) =>
                    report(cause, 'That comment could not be saved.'),
                );

            return comment;
        },
        onSuccess: (comment) => {
            setError(null);
            patch((task) => ({
                ...task,
                comments: [...(task.comments ?? []), comment],
            }));
        },
    });

    const editComment = useMutation({
        mutationFn: async ({ id, body }: { id: number; body: string }) => {
            const { comment } = await api
                .patch<{ comment: TaskComment }>(
                    commentUpdate.url({
                        workspace: workspaceSlug,
                        task: taskId ?? 0,
                        comment: id,
                    }),
                    { body },
                )
                .catch((cause: unknown) =>
                    report(cause, 'That comment could not be saved.'),
                );

            return comment;
        },
        onSuccess: (comment) => {
            setError(null);
            patch((task) => ({
                ...task,
                comments: (task.comments ?? []).map((row) =>
                    row.id === comment.id ? comment : row,
                ),
            }));
        },
    });

    const removeComment = useMutation({
        mutationFn: async (id: number) => {
            await api
                .delete(
                    commentDestroy.url({
                        workspace: workspaceSlug,
                        task: taskId ?? 0,
                        comment: id,
                    }),
                )
                .catch((cause: unknown) =>
                    report(cause, 'That comment could not be deleted.'),
                );

            return id;
        },
        onSuccess: (id) => {
            setError(null);
            patch((task) => ({
                ...task,
                comments: (task.comments ?? []).filter((row) => row.id !== id),
            }));
        },
    });

    /**
     * One file, straight onto the card.
     *
     * No tray and nothing to claim afterwards: unlike the composer, the thing
     * the file belongs to already exists when it is chosen.
     */
    const [uploading, setUploading] = useState<string[]>([]);

    const addFile = useCallback(
        async (file: File) => {
            setUploading((names) => [...names, file.name]);

            try {
                const { attachment } = await api.upload<{
                    attachment: Attachment;
                }>(
                    attachmentStore.url({
                        workspace: workspaceSlug,
                        task: taskId ?? 0,
                    }),
                    file,
                );

                setError(null);
                patch((task) => ({
                    ...task,
                    attachments: [...(task.attachments ?? []), attachment],
                }));
            } catch (cause) {
                setError(
                    cause instanceof ApiError
                        ? cause.userMessage
                        : 'That file could not be uploaded.',
                );
            } finally {
                setUploading((names) => {
                    const index = names.indexOf(file.name);

                    return index === -1
                        ? names
                        : [...names.slice(0, index), ...names.slice(index + 1)];
                });
            }
        },
        [patch, taskId, workspaceSlug],
    );

    const removeFile = useCallback(
        async (attachment: Attachment) => {
            try {
                await api.delete(
                    attachmentDestroy.url({
                        workspace: workspaceSlug,
                        task: taskId ?? 0,
                        attachment: attachment.id,
                    }),
                );

                setError(null);
                patch((task) => ({
                    ...task,
                    attachments: (task.attachments ?? []).filter(
                        (row) => row.id !== attachment.id,
                    ),
                }));
            } catch (cause) {
                setError(
                    cause instanceof ApiError
                        ? cause.userMessage
                        : 'That file could not be removed.',
                );
            }
        },
        [patch, taskId, workspaceSlug],
    );

    /**
     * Web links and linked cards. Each write answers with what the panel
     * should now show, so the cache is set rather than refetched.
     */
    const run = useCallback(
        async (action: () => Promise<void>, fallback: string) => {
            try {
                await action();
                setError(null);
            } catch (cause) {
                setError(
                    cause instanceof ApiError ? cause.userMessage : fallback,
                );

                throw cause;
            }
        },
        [],
    );

    const addLink = useCallback(
        (url: string, title: string | null) =>
            run(async () => {
                const { link } = await api.post<{ link: TaskLink }>(
                    linkStore.url({
                        workspace: workspaceSlug,
                        task: taskId ?? 0,
                    }),
                    { url, title },
                );

                patch((task) => ({
                    ...task,
                    links: [
                        ...(task.links ?? []).filter(
                            (row) => row.id !== link.id,
                        ),
                        link,
                    ],
                }));
            }, 'That link could not be added.'),
        [patch, run, taskId, workspaceSlug],
    );

    const removeLink = useCallback(
        (id: number) =>
            run(async () => {
                await api.delete(
                    linkDestroy.url({
                        workspace: workspaceSlug,
                        task: taskId ?? 0,
                        link: id,
                    }),
                );

                patch((task) => ({
                    ...task,
                    links: (task.links ?? []).filter((row) => row.id !== id),
                }));
            }, 'That link could not be removed.').catch(noop),
        [patch, run, taskId, workspaceSlug],
    );

    const addRelation = useCallback(
        (relatedTaskId: number, type: TaskRelationType) =>
            run(async () => {
                const { relations } = await api.post<{
                    relations: TaskRelation[];
                }>(
                    relationStore.url({
                        workspace: workspaceSlug,
                        task: taskId ?? 0,
                    }),
                    { related_task_id: relatedTaskId, type },
                );

                patch((task) => ({ ...task, relations }));
            }, 'Those tasks could not be linked.'),
        [patch, run, taskId, workspaceSlug],
    );

    const removeRelation = useCallback(
        (id: number) =>
            run(async () => {
                const { relations } = await api.delete<{
                    relations: TaskRelation[];
                }>(
                    relationDestroy.url({
                        workspace: workspaceSlug,
                        task: taskId ?? 0,
                        relation: id,
                    }),
                );

                patch((task) => ({ ...task, relations }));
            }, 'That link could not be removed.').catch(noop),
        [patch, run, taskId, workspaceSlug],
    );

    return {
        /** Null until the card's own request lands. */
        detail: query.data ?? null,
        isLoading: query.isLoading,
        /** What went wrong with the last write, for a bar the reader can see. */
        error,
        dismissError: useCallback(() => setError(null), []),
        addComment: (body: string) => addComment.mutateAsync(body).catch(noop),
        editComment: (id: number, body: string) =>
            editComment.mutateAsync({ id, body }).catch(noop),
        removeComment: (id: number) =>
            removeComment.mutateAsync(id).catch(noop),
        isSavingComment: addComment.isPending || editComment.isPending,
        addFile,
        removeFile,
        /** Names of the files still going up, so the list can say so. */
        uploading,
        /** Rejects on failure, so a form can keep what was typed. */
        addLink,
        removeLink,
        /** Rejects on failure, so the picker can stay open. */
        addRelation,
        removeRelation,
        /** Re-read the card, after a change made through another endpoint. */
        refresh: useCallback(
            () =>
                client.invalidateQueries({ queryKey: ['task-detail', taskId] }),
            [client, taskId],
        ),
    };
}

/**
 * Mutations report through `error` above; the rejection itself is expected and
 * would otherwise surface as an unhandled promise.
 */
function noop() {
    return undefined;
}
