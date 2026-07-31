import { AlertCircle, FileIcon, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { PendingUpload } from '@/types';

/**
 * The files waiting to go out with the next message.
 *
 * Sits above the textarea rather than below it, so it does not push the send
 * button around as files are added — and so the author's eye lands on what they
 * attached before they finish typing about it.
 *
 * Each row shows one of three states, and only one: uploading, failed, or ready.
 * A progress bar that stays at 100% after the upload finished tells the author
 * nothing, and a failure has to be visibly different from a slow upload or they
 * will wait for something that is never going to happen.
 */
export function AttachmentTray({
    uploads,
    onRemove,
}: {
    uploads: PendingUpload[];
    onRemove: (nonce: string) => void;
}) {
    if (uploads.length === 0) {
        return null;
    }

    return (
        <ul className="mb-2 flex flex-wrap gap-2">
            {uploads.map((upload) => (
                <li key={upload.nonce}>
                    <TrayItem upload={upload} onRemove={onRemove} />
                </li>
            ))}
        </ul>
    );
}

function TrayItem({
    upload,
    onRemove,
}: {
    upload: PendingUpload;
    onRemove: (nonce: string) => void;
}) {
    const failed = upload.error !== null;
    const uploading = !failed && upload.attachment === null;

    return (
        <div
            className={cn(
                'relative flex w-56 items-center gap-2.5 rounded-lg border bg-card p-2 pr-8',
                failed ? 'border-danger/50 bg-danger/5' : 'border-border',
            )}
        >
            <span
                className={cn(
                    'relative flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md',
                    failed
                        ? 'bg-danger/10 text-danger'
                        : 'bg-brand-subtle text-brand',
                )}
            >
                {upload.localPreview !== null && !failed ? (
                    /*
                     * The local object URL, not the server's thumbnail: the
                     * thumbnail is generated on the queue and does not exist yet
                     * while the tray is open, and the point of this preview is
                     * that it appears the instant the file is chosen.
                     */
                    <img
                        src={upload.localPreview}
                        alt=""
                        className={cn(
                            'size-full object-cover',
                            uploading && 'opacity-60',
                        )}
                    />
                ) : failed ? (
                    <AlertCircle aria-hidden="true" className="size-4" />
                ) : (
                    <FileIcon aria-hidden="true" className="size-4" />
                )}
            </span>

            <span className="min-w-0 flex-1">
                <span className="block truncate text-xs font-medium">
                    {upload.name}
                </span>

                {failed ? (
                    <span className="block truncate text-[0.6875rem] text-danger">
                        {upload.error}
                    </span>
                ) : uploading ? (
                    <span
                        role="progressbar"
                        aria-valuenow={upload.progress}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label={`Uploading ${upload.name}`}
                        className="mt-1 block h-1 w-full overflow-hidden rounded-full bg-muted"
                    >
                        <span
                            className="block h-full rounded-full bg-brand transition-[width] duration-150"
                            style={{ width: `${upload.progress}%` }}
                        />
                    </span>
                ) : (
                    <span className="block text-[0.6875rem] text-muted-foreground">
                        {upload.attachment?.human_size ?? 'Ready'}
                    </span>
                )}
            </span>

            <button
                type="button"
                onClick={() => onRemove(upload.nonce)}
                className="absolute top-1.5 right-1.5 rounded p-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
                <X aria-hidden="true" className="size-3.5" />
                <span className="sr-only">Remove {upload.name}</span>
            </button>
        </div>
    );
}
