import { router } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { store as storeAsset } from '@/routes/assets';

type Queued = {
    /** Stable across renders so a row keeps its identity as the queue drains. */
    key: string;
    name: string;
    /** 0–100, or null before the browser has reported anything. */
    progress: number | null;
    error: string | null;
};

/**
 * Adding files to the library.
 *
 * # Why the files go up one at a time
 *
 * Inertia allows one visit at a time and cancels the previous one when a new
 * starts, so firing a request per file would upload the last file and silently
 * discard the rest — with the progress bars for the cancelled ones frozen
 * wherever they happened to be. The queue below is not a politeness about
 * bandwidth; it is what makes selecting eight files add eight things.
 *
 * # Why an Inertia visit rather than XHR
 *
 * The composer's tray uploads over raw XHR to a JSON endpoint, because the file
 * is going beside a message that does not exist yet and the page must not move
 * while the author is typing. Nothing here is like that: each upload's outcome
 * is one more row in the list being looked at, and the redirect that carries it
 * back is the confirmation.
 */
export function AssetUploader({
    workspaceSlug,
    folderId,
    className,
    onReady,
}: {
    workspaceSlug: string;
    /** The shelf files land on. Null is the top of the library. */
    folderId: number | null;
    className?: string;
    /**
     * Hands the queue to whoever owns the drop target.
     *
     * The button and the drop zone are in different parts of the page — the
     * button in the header, the zone over the whole gallery — but they are one
     * queue, because two would upload the same eight files as two competing
     * sequences and Inertia would cancel one of them. See {@see useFileDrop}.
     */
    onReady?: (enqueue: (files: File[]) => void) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [queue, setQueue] = useState<Queued[]>([]);

    /*
     * The pending files, mirrored outside React state.
     *
     * `drain` runs across several awaits and has to see files added while it was
     * mid-upload — a second drop, or a second Choose files. Reading state inside
     * it would read the value captured when it started, so the ref is written
     * alongside every setState rather than derived from one.
     */
    const pending = useRef<{ key: string; file: File }[]>([]);
    const draining = useRef(false);

    const enqueue = useCallback(
        (files: File[]) => {
            if (files.length === 0) {
                return;
            }

            const rows = files.map((file, index) => ({
                key: `${Date.now()}-${index}-${file.name}`,
                file,
            }));

            pending.current = [...pending.current, ...rows];

            setQueue((current) => [
                ...current,
                ...rows.map(({ key, file }) => ({
                    key,
                    name: file.name,
                    progress: null,
                    error: null,
                })),
            ]);

            if (draining.current) {
                return;
            }

            draining.current = true;

            const next = () => {
                const item = pending.current.shift();

                if (item === undefined) {
                    draining.current = false;

                    return;
                }

                router.post(
                    storeAsset(workspaceSlug).url,
                    {
                        file: item.file,
                        ...(folderId === null ? {} : { folder_id: folderId }),
                    },
                    {
                        forceFormData: true,
                        preserveScroll: true,
                        // The gallery and the tree both change; everything else
                        // on the page is unaffected by one more file.
                        only: ['assets', 'folders', 'folder', 'next_cursor'],
                        onProgress: (event) => {
                            setQueue((current) =>
                                current.map((row) =>
                                    row.key === item.key
                                        ? {
                                              ...row,
                                              progress: Math.round(
                                                  event?.percentage ?? 0,
                                              ),
                                          }
                                        : row,
                                ),
                            );
                        },
                        onSuccess: () => {
                            setQueue((current) =>
                                current.filter((row) => row.key !== item.key),
                            );
                        },
                        onError: (errors) => {
                            /*
                             * The row stays, carrying the reason. A file the
                             * server refused — too large, an executable —
                             * silently vanishing from the queue reads as the
                             * upload having worked.
                             */
                            setQueue((current) =>
                                current.map((row) =>
                                    row.key === item.key
                                        ? {
                                              ...row,
                                              progress: null,
                                              error:
                                                  errors.file ??
                                                  errors.folder_id ??
                                                  'That file could not be added.',
                                          }
                                        : row,
                                ),
                            );
                        },
                        onFinish: next,
                    },
                );
            };

            next();
        },
        [folderId, workspaceSlug],
    );

    useEffect(() => {
        onReady?.(enqueue);
    }, [enqueue, onReady]);

    return (
        <div className={className}>
            <input
                ref={input}
                type="file"
                multiple
                className="sr-only"
                onChange={(event) => {
                    enqueue(Array.from(event.target.files ?? []));

                    // Cleared so choosing the same file twice in a row fires
                    // change the second time.
                    event.target.value = '';
                }}
            />

            <Button
                type="button"
                size="sm"
                onClick={() => input.current?.click()}
            >
                <Upload aria-hidden="true" className="size-4" />
                Add files
            </Button>

            <UploadQueue
                queue={queue}
                onDismiss={(key) =>
                    setQueue((current) =>
                        current.filter((row) => row.key !== key),
                    )
                }
            />
        </div>
    );
}

/**
 * Drag and drop over a region, as a hook so the drop target can be the gallery
 * itself rather than a separate strip nobody aims at.
 *
 * Returns the props to spread onto the region and whether something is
 * currently over it. The counter is what makes the highlight stable: dragging
 * across a child fires `dragleave` on the parent, so a boolean flickers off and
 * on for every element the pointer crosses.
 */
export function useFileDrop(onFiles: (files: File[]) => void) {
    const depth = useRef(0);
    const [isOver, setIsOver] = useState(false);

    // A drag that ends outside the window fires no drop and no leave, so the
    // highlight would stay on forever without this.
    useEffect(() => {
        const reset = () => {
            depth.current = 0;
            setIsOver(false);
        };

        window.addEventListener('dragend', reset);
        window.addEventListener('drop', reset);

        return () => {
            window.removeEventListener('dragend', reset);
            window.removeEventListener('drop', reset);
        };
    }, []);

    return {
        isOver,
        dropProps: {
            onDragEnter: (event: React.DragEvent) => {
                event.preventDefault();
                depth.current += 1;
                setIsOver(true);
            },
            onDragOver: (event: React.DragEvent) => event.preventDefault(),
            onDragLeave: () => {
                depth.current = Math.max(0, depth.current - 1);

                if (depth.current === 0) {
                    setIsOver(false);
                }
            },
            onDrop: (event: React.DragEvent) => {
                event.preventDefault();
                depth.current = 0;
                setIsOver(false);

                const files = Array.from(event.dataTransfer.files ?? []);

                if (files.length > 0) {
                    onFiles(files);
                }
            },
        },
    };
}

function UploadQueue({
    queue,
    onDismiss,
}: {
    queue: Queued[];
    onDismiss: (key: string) => void;
}) {
    if (queue.length === 0) {
        return null;
    }

    return (
        <ul className="mt-2 space-y-1.5" aria-label="Uploads in progress">
            {queue.map((row) => (
                <li
                    key={row.key}
                    className="rounded-lg border border-border bg-card px-3 py-2 text-xs"
                >
                    <div className="flex items-center gap-2">
                        <span className="min-w-0 flex-1 truncate font-medium">
                            {row.name}
                        </span>

                        {row.error === null ? (
                            <span className="shrink-0 text-muted-foreground tabular-nums">
                                {row.progress === null
                                    ? 'Waiting'
                                    : `${row.progress}%`}
                            </span>
                        ) : (
                            <button
                                type="button"
                                onClick={() => onDismiss(row.key)}
                                className="shrink-0 text-muted-foreground underline-offset-2 hover:underline"
                            >
                                Dismiss
                            </button>
                        )}
                    </div>

                    {row.error === null ? (
                        <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded-full bg-brand transition-[width]',
                                )}
                                style={{ width: `${row.progress ?? 0}%` }}
                            />
                        </div>
                    ) : (
                        <p className="mt-1 text-destructive">{row.error}</p>
                    )}
                </li>
            ))}
        </ul>
    );
}
