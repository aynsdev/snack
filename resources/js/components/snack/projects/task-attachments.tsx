import {
    Download,
    FileAudio,
    FileText,
    FileVideo,
    File as FileIcon,
    ImageIcon,
    Loader2,
    Paperclip,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Attachment, AttachmentKind } from '@/types';

/** Keyed off the server's `kind`, so this and the Files browser agree. */
const KIND_ICONS: Record<AttachmentKind, typeof FileIcon> = {
    image: ImageIcon,
    video: FileVideo,
    audio: FileAudio,
    pdf: FileText,
    file: FileIcon,
};

/**
 * The files on a card.
 *
 * A list of rows rather than the timeline's preview grid: what gets attached to
 * a card is a spec, a log, a screenshot of the bug — things to open, not things
 * to look at in place. Images still show their thumbnail in the row, which is
 * enough to tell three screenshots apart.
 *
 * Dropping onto the panel works as well as the button, because that is how a
 * screenshot actually arrives.
 */
export function TaskAttachments({
    attachments,
    uploading,
    canContribute,
    onAdd,
    onRemove,
}: {
    attachments: Attachment[];
    /** Names of files still going up. */
    uploading: string[];
    canContribute: boolean;
    onAdd: (file: File) => void;
    onRemove: (attachment: Attachment) => void;
}) {
    const picker = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    const accept = (files: FileList | null) => {
        for (const file of Array.from(files ?? [])) {
            onAdd(file);
        }
    };

    return (
        <section
            className="mt-6 border-t border-border pt-4"
            onDragOver={(event) => {
                if (!canContribute) {
                    return;
                }

                event.preventDefault();
                setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={(event) => {
                if (!canContribute) {
                    return;
                }

                event.preventDefault();
                setDragging(false);
                accept(event.dataTransfer.files);
            }}
        >
            <div className="flex items-center justify-between gap-2">
                <h3 className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    <Paperclip aria-hidden="true" className="size-3.5" />
                    Attachments
                    {attachments.length > 0 && (
                        <span className="tabular-nums">
                            ({attachments.length})
                        </span>
                    )}
                </h3>

                {canContribute && (
                    <>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => picker.current?.click()}
                        >
                            <Paperclip aria-hidden="true" />
                            Add file
                        </Button>

                        <input
                            ref={picker}
                            type="file"
                            multiple
                            aria-label="Attach a file"
                            className="hidden"
                            onChange={(event) => {
                                accept(event.target.files);
                                // Cleared so choosing the same file twice in a
                                // row still fires a change.
                                event.target.value = '';
                            }}
                        />
                    </>
                )}
            </div>

            <div
                className={cn(
                    'mt-2 space-y-1.5 rounded-lg transition-colors',
                    dragging &&
                        'ring-2 ring-brand ring-offset-2 ring-offset-background',
                )}
            >
                {attachments.map((attachment) => (
                    <AttachmentRow
                        key={attachment.id}
                        attachment={attachment}
                        canRemove={canContribute}
                        onRemove={() => onRemove(attachment)}
                    />
                ))}

                {uploading.map((name) => (
                    <p
                        key={name}
                        className="flex items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2 text-sm text-muted-foreground"
                    >
                        <Loader2
                            aria-hidden="true"
                            className="size-4 animate-spin"
                        />
                        <span className="truncate">{name}</span>
                    </p>
                ))}

                {attachments.length === 0 && uploading.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {canContribute
                            ? 'Drop a file here, or use Add file.'
                            : 'No files on this card.'}
                    </p>
                )}
            </div>
        </section>
    );
}

function AttachmentRow({
    attachment,
    canRemove,
    onRemove,
}: {
    attachment: Attachment;
    canRemove: boolean;
    onRemove: () => void;
}) {
    const [confirming, setConfirming] = useState(false);
    const Icon = KIND_ICONS[attachment.kind];
    const thumbnail = attachment.thumbnail_url ?? attachment.preview_url;

    return (
        <div className="flex items-center gap-2.5 rounded-lg border border-border bg-card px-3 py-2">
            {thumbnail === null ? (
                <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-brand-subtle text-brand">
                    <Icon aria-hidden="true" className="size-4" />
                </span>
            ) : (
                <img
                    src={thumbnail}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="size-9 shrink-0 rounded-md object-cover"
                />
            )}

            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">
                    {attachment.name}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {attachment.human_size}
                </span>
            </span>

            <a
                href={attachment.download_url}
                // The server sends Content-Disposition: attachment; this is a
                // hint to the browser rather than the mechanism.
                download={attachment.name}
                aria-label={`Download ${attachment.name}`}
                className="rounded p-1 text-muted-foreground transition-colors hover:text-foreground"
            >
                <Download aria-hidden="true" className="size-4" />
            </a>

            {canRemove &&
                (confirming ? (
                    <span className="flex items-center gap-2 text-xs">
                        <button
                            type="button"
                            onClick={onRemove}
                            className="text-destructive"
                        >
                            Remove
                        </button>
                        <button
                            type="button"
                            onClick={() => setConfirming(false)}
                            className="text-muted-foreground transition-colors hover:text-foreground"
                        >
                            Keep
                        </button>
                    </span>
                ) : (
                    <button
                        type="button"
                        aria-label={`Remove ${attachment.name}`}
                        onClick={() => setConfirming(true)}
                        className="rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                    >
                        <X aria-hidden="true" className="size-4" />
                    </button>
                ))}
        </div>
    );
}
