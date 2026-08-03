import {
    Download,
    FileAudio,
    FileText,
    FileVideo,
    File as FileIcon,
    ImageIcon,
} from 'lucide-react';
import { useState } from 'react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { Attachment, AttachmentKind } from '@/types';

/**
 * The icon for a file that has no picture of its own.
 *
 * Keyed off the server's `kind` rather than the extension, so the chip and the
 * Files browser's type filter can never disagree about what something is.
 */
const KIND_ICONS: Record<AttachmentKind, typeof FileIcon> = {
    image: ImageIcon,
    video: FileVideo,
    audio: FileAudio,
    pdf: FileText,
    file: FileIcon,
};

/**
 * The files attached to a message.
 *
 * Images render as previews and everything else as a chip, because those are two
 * genuinely different things to a reader: a screenshot is the message, and a
 * spreadsheet is a thing to open later. Collapsing both into one row of
 * filenames would bury the first; previewing both would fill the timeline with
 * grey rectangles labelled "budget.xlsx".
 *
 * Previews are capped in height rather than shown at full size — a tall
 * screenshot must not push the rest of the conversation off the screen — and
 * clicking one opens it properly.
 */
export function AttachmentList({
    attachments,
    className,
}: {
    attachments: Attachment[];
    className?: string;
}) {
    const [viewing, setViewing] = useState<Attachment | null>(null);

    if (attachments.length === 0) {
        return null;
    }

    const images = attachments.filter(
        (file) => file.kind === 'image' && file.preview_url !== null,
    );
    const others = attachments.filter((file) => !images.includes(file));

    return (
        <div className={cn('mt-1.5 space-y-1.5', className)}>
            {images.length > 0 && (
                <div
                    className={cn(
                        'flex flex-wrap gap-1.5',
                        // A lone image gets room to be looked at; a set of them
                        // becomes a contact sheet rather than a stack.
                        images.length === 1 ? 'max-w-lg' : 'max-w-2xl',
                    )}
                >
                    {images.map((file) => (
                        <ImagePreview
                            key={file.id}
                            attachment={file}
                            solo={images.length === 1}
                            onOpen={() => setViewing(file)}
                        />
                    ))}
                </div>
            )}

            {others.map((file) => (
                <FileChip key={file.id} attachment={file} />
            ))}

            <Lightbox attachment={viewing} onClose={() => setViewing(null)} />
        </div>
    );
}

/**
 * One image in the timeline.
 *
 * Renders the generated thumbnail when there is one and the original otherwise.
 * The thumbnail is written by a queued job, so a picture that has only just been
 * sent legitimately has none yet — falling back rather than waiting means the
 * sender sees their image immediately instead of a placeholder that resolves
 * seconds later.
 *
 * # Except when it moves
 *
 * A thumbnail is a single frame: GD decodes only the first one, so a GIF
 * rendered through it is a still picture of something everybody expects to
 * animate. Animated files therefore show the original, which is the only copy
 * that plays.
 *
 * That is a deliberate trade of bytes for behaviour, and it is already bounded:
 * `preview_url` is null above the inline size ceiling, and {@see AttachmentList}
 * routes anything without one to a file chip — so an enormous GIF never reaches
 * here to be pulled into a scrolling timeline in the first place.
 *
 * `width`/`height` come from the server already corrected for EXIF orientation,
 * which is what reserves the right space before the bytes arrive. They used to
 * be the raw buffer's dimensions while the thumbnail beside them had been
 * rotated upright, so a portrait photo was poured into a landscape box and read
 * as though the image had been rotated.
 */
function ImagePreview({
    attachment,
    solo,
    onOpen,
}: {
    attachment: Attachment;
    solo: boolean;
    onOpen: () => void;
}) {
    /*
     * `preview_url` is non-null for everything that reaches here — the caller
     * filters on it — so an animated file always has an original to play.
     */
    const source = attachment.is_animated
        ? attachment.preview_url
        : (attachment.thumbnail_url ?? attachment.preview_url);

    return (
        <button
            type="button"
            onClick={onOpen}
            className={cn(
                'group/image relative overflow-hidden rounded-lg border border-border bg-muted/40',
                'transition-opacity hover:opacity-90 focus-visible:ring-2 focus-visible:ring-brand',
                solo ? 'max-h-80' : 'size-40',
            )}
        >
            <img
                src={source ?? ''}
                alt={attachment.name}
                width={attachment.width ?? undefined}
                height={attachment.height ?? undefined}
                loading="lazy"
                decoding="async"
                className={cn(
                    solo
                        ? 'max-h-80 w-auto max-w-full object-contain'
                        : 'size-full object-cover',
                )}
            />

            <span className="sr-only">Open {attachment.name}</span>
        </button>
    );
}

/**
 * A file that is not a picture: an icon, a name, a size, and a download.
 *
 * The whole chip is the download link rather than a row with a small button at
 * the end — the only thing anyone wants from a chip is the file.
 */
export function FileChip({
    attachment,
    className,
}: {
    attachment: Attachment;
    className?: string;
}) {
    const Icon = KIND_ICONS[attachment.kind];

    return (
        <a
            href={attachment.download_url}
            // The server sends Content-Disposition: attachment, so this is a
            // hint to the browser rather than the mechanism.
            download={attachment.name}
            className={cn(
                'flex max-w-md items-center gap-2.5 rounded-lg border border-border bg-card px-3 py-2',
                'transition-colors hover:border-brand/40 hover:bg-accent/40',
                className,
            )}
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-brand-subtle text-brand">
                <Icon aria-hidden="true" className="size-4" />
            </span>

            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">
                    {attachment.name}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {attachment.human_size}
                </span>
            </span>

            <Download
                aria-hidden="true"
                className="size-4 shrink-0 text-muted-foreground"
            />
        </a>
    );
}

/**
 * Full-size image viewing.
 *
 * Built on the project's dialog primitive rather than a hand-rolled overlay, so
 * it portals out of the timeline's scroll container, traps focus, and closes on
 * Escape without any of that being reimplemented here.
 */
function Lightbox({
    attachment,
    onClose,
}: {
    attachment: Attachment | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={attachment !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-w-[min(92vw,72rem)] gap-0 p-4 sm:max-w-[min(92vw,72rem)]">
                <DialogTitle className="sr-only">
                    {attachment?.name ?? 'Image'}
                </DialogTitle>

                {attachment !== null && (
                    <figure className="flex flex-col items-center gap-3">
                        <img
                            src={
                                attachment.preview_url ??
                                attachment.download_url
                            }
                            alt={attachment.name}
                            className="max-h-[75vh] w-auto max-w-full rounded-lg object-contain"
                        />

                        <figcaption className="flex flex-wrap items-center justify-center gap-3 text-sm">
                            <span className="max-w-64 truncate font-medium">
                                {attachment.name}
                            </span>

                            <span className="text-muted-foreground">
                                {attachment.human_size}
                            </span>

                            <a
                                href={attachment.download_url}
                                download={attachment.name}
                                className="flex items-center gap-1.5 font-semibold text-brand hover:underline"
                            >
                                <Download
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Download
                            </a>
                        </figcaption>
                    </figure>
                )}
            </DialogContent>
        </Dialog>
    );
}
