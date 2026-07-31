import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, api } from '@/lib/api';
import { createNonce } from '@/lib/nonce';
import {
    destroy as uploadDestroy,
    store as uploadStore,
} from '@/routes/uploads';
import type { Attachment, PendingUpload } from '@/types';

/** Matches config('snack.uploads.per_message'); the server is the authority. */
export const MAX_FILES_PER_MESSAGE = 10;

/** Matches config('snack.uploads.max_size_kb'), in bytes. */
export const MAX_FILE_SIZE = 50 * 1024 * 1024;

/**
 * The composer's attachment tray.
 *
 * # Why files upload before the message is sent
 *
 * Each file goes up the moment it is chosen, while the composer is still open.
 * That is what lets a progress bar run while the author keeps typing, and what
 * stops a 40 MB video being re-sent every time they fix a typo. The server parks
 * the bytes with no `message_id` and hands back an id; sending the message
 * claims them.
 *
 * The consequence is that a tray abandoned without sending leaves uploads on the
 * server. Those are swept by `snack:prune-uploads` rather than by this hook —
 * a client cannot be relied on to clean up after itself, since the most common
 * way to abandon a composer is to close the tab.
 *
 * # Local previews
 *
 * An image gets an object URL immediately so the tray shows the picture rather
 * than a filename while the bytes are still going up. Those URLs pin the file in
 * memory until revoked, so every path that drops a row revokes its own — and the
 * unmount cleanup catches the rest.
 */
export function useUploads({ workspaceSlug }: { workspaceSlug: string }) {
    const [uploads, setUploads] = useState<PendingUpload[]>([]);

    /*
     * The tray, mirrored into a ref that every mutator writes alongside the
     * state.
     *
     * Two things need it. The unmount cleanup has to revoke the object URLs of
     * whatever is in the tray, and it cannot depend on `uploads` without
     * re-registering on every progress tick. And `add` has to know how full the
     * tray already is *now* — several uploads report progress independently, so
     * a decision made from a render-old copy would be wrong by however many
     * callbacks fired since.
     *
     * Written only inside callbacks, never during render: a ref mutated while
     * rendering is a value React is entitled to discard.
     */
    const current = useRef<PendingUpload[]>([]);

    const commit = useCallback(
        (next: (rows: PendingUpload[]) => PendingUpload[]) => {
            current.current = next(current.current);
            setUploads(current.current);
        },
        [],
    );

    useEffect(
        () => () => {
            for (const upload of current.current) {
                if (upload.localPreview !== null) {
                    URL.revokeObjectURL(upload.localPreview);
                }
            }
        },
        [],
    );

    const patch = useCallback(
        (nonce: string, changes: Partial<PendingUpload>) => {
            commit((rows) =>
                rows.map((row) =>
                    row.nonce === nonce ? { ...row, ...changes } : row,
                ),
            );
        },
        [commit],
    );

    const add = useCallback(
        (files: File[]) => {
            if (files.length === 0) {
                return;
            }

            /*
             * Bounded here as well as server-side, and against the tray's
             * current size rather than the batch's — dropping five files onto a
             * tray that already holds eight has to refuse the last three, not
             * accept all five.
             */
            const room = MAX_FILES_PER_MESSAGE - current.current.length;
            const accepted = files.slice(0, Math.max(0, room));

            const rows: PendingUpload[] = accepted.map((file) => ({
                nonce: createNonce(),
                name: file.name,
                size: file.size,
                progress: 0,
                attachment: null,
                // Oversize files are rejected without ever being sent: there is
                // no point spending a minute uploading something the server will
                // refuse on arrival.
                error:
                    file.size > MAX_FILE_SIZE
                        ? 'This file is too large to share.'
                        : null,
                localPreview: file.type.startsWith('image/')
                    ? URL.createObjectURL(file)
                    : null,
            }));

            commit((existing) => [...existing, ...rows]);

            rows.forEach((row, index) => {
                if (row.error !== null) {
                    return;
                }

                void api
                    .upload<{ attachment: Attachment }>(
                        uploadStore.url(workspaceSlug),
                        accepted[index],
                        (percent) => patch(row.nonce, { progress: percent }),
                    )
                    .then(({ attachment }) =>
                        patch(row.nonce, { attachment, progress: 100 }),
                    )
                    .catch((error: unknown) =>
                        patch(row.nonce, {
                            error:
                                error instanceof ApiError
                                    ? error.userMessage
                                    : 'That file could not be uploaded.',
                        }),
                    );
            });
        },
        [commit, patch, workspaceSlug],
    );

    const remove = useCallback(
        (nonce: string) => {
            const row = current.current.find((item) => item.nonce === nonce);

            if (row === undefined) {
                return;
            }

            if (row.localPreview !== null) {
                URL.revokeObjectURL(row.localPreview);
            }

            commit((rows) => rows.filter((item) => item.nonce !== nonce));

            /*
             * Fire-and-forget. The row is already gone from the tray, and a
             * failed delete leaves an unattached upload the nightly sweep will
             * collect — so there is nothing here for the author to act on.
             */
            if (row.attachment !== null) {
                void api
                    .delete(
                        uploadDestroy.url({
                            workspace: workspaceSlug,
                            attachment: row.attachment.id,
                        }),
                    )
                    .catch(() => undefined);
            }
        },
        [commit, workspaceSlug],
    );

    /**
     * Empty the tray after a successful send.
     *
     * Does *not* delete anything server-side: those uploads now belong to the
     * message that just claimed them.
     */
    const clear = useCallback(() => {
        commit((rows) => {
            for (const row of rows) {
                if (row.localPreview !== null) {
                    URL.revokeObjectURL(row.localPreview);
                }
            }

            return [];
        });
    }, [commit]);

    const ready = uploads.filter((row) => row.attachment !== null);

    return {
        uploads,
        add,
        remove,
        clear,
        /** What a send should claim. */
        attachmentIds: ready.map((row) => row.attachment?.id ?? 0),
        /** True while any file is still going up — send waits for this. */
        isUploading: uploads.some(
            (row) => row.attachment === null && row.error === null,
        ),
        hasFiles: uploads.length > 0,
        isFull: uploads.length >= MAX_FILES_PER_MESSAGE,
    };
}
