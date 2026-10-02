import {
    Download,
    Eye,
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
 * Images render as previews, videos as players, and everything else as a chip, because those are two
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
    const pdfs = attachments.filter(
        (file) => file.kind === 'pdf' && file.preview_url !== null,
    );
    const videos = attachments.filter(
        (file) => file.kind === 'video' && file.stream_url !== null,
    );
    const others = attachments.filter(
        (file) =>
            !images.includes(file) &&
            !pdfs.includes(file) &&
            !videos.includes(file),
    );

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

            {pdfs.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {pdfs.map((file) => (
                        <PdfThumbnail
                            key={file.id}
                            attachment={file}
                            onOpen={() => setViewing(file)}
                        />
                    ))}
                </div>
            )}

            {videos.map((file) => (
                <VideoPreview key={file.id} attachment={file} />
            ))}

            {others.map((file) => (
                <FileChip key={file.id} attachment={file} />
            ))}

            <AttachmentPreview
                attachment={viewing}
                onClose={() => setViewing(null)}
            />
        </div>
    );
}

/** A compact first-page glimpse that opens the PDF without downloading it. */
function PdfThumbnail({
    attachment,
    onOpen,
}: {
    attachment: Attachment;
    onOpen: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className={cn(
                'group/pdf w-64 max-w-full overflow-hidden rounded-lg border border-border bg-card text-left',
                'transition-colors hover:border-brand/40 focus-visible:ring-2 focus-visible:ring-brand',
            )}
        >
            <span className="relative block aspect-4/3 overflow-hidden bg-muted">
                <iframe
                    src={attachment.preview_url ?? ''}
                    title={`${attachment.name} preview thumbnail`}
                    tabIndex={-1}
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 size-full bg-background"
                />

                <span className="absolute right-2 bottom-2 flex size-8 items-center justify-center rounded-md border border-border bg-background/90 text-foreground shadow-sm">
                    <Eye aria-hidden="true" className="size-4" />
                </span>
            </span>

            <span className="flex items-center gap-2.5 px-3 py-2">
                <FileText
                    aria-hidden="true"
                    className="size-4 shrink-0 text-brand"
                />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {attachment.name}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {attachment.human_size}
                    </span>
                </span>
            </span>
        </button>
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
 * A video, played where it was sent.
 *
 * `preload="metadata"` fetches the first frame and the duration through a range
 * request and nothing more, so a timeline full of recordings costs a few
 * kilobytes each until somebody presses play. The native controls bring
 * fullscreen, scrubbing, and picture-in-picture without reimplementing any of
 * them, which is why there is no dialog here as there is for images.
 */
function VideoPreview({ attachment }: { attachment: Attachment }) {
    return (
        <figure className="w-full max-w-lg overflow-hidden rounded-lg border border-border bg-card">
            <video
                src={attachment.stream_url ?? undefined}
                controls
                playsInline
                preload="metadata"
                aria-label={attachment.name}
                className="max-h-80 w-full bg-black"
            />

            <figcaption className="flex items-center gap-2.5 px-3 py-2">
                <FileVideo
                    aria-hidden="true"
                    className="size-4 shrink-0 text-brand"
                />
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
                    download={attachment.name}
                    className="flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-brand"
                >
                    <Download aria-hidden="true" className="size-4" />
                    <span className="sr-only">Download {attachment.name}</span>
                </a>
            </figcaption>
        </figure>
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
                'flex max-w-md items-center gap-2.5 rounded-lg border border-border bg-card px-3 py-2 text-left',
                'transition-colors hover:border-brand/40 hover:bg-accent/40 focus-visible:ring-2 focus-visible:ring-brand',
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
 * Full-size attachment viewing.
 *
 * Built on the project's dialog primitive rather than a hand-rolled overlay, so
 * it portals out of the timeline's scroll container, traps focus, and closes on
 * Escape without any of that being reimplemented here.
 */
function AttachmentPreview({
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
            <DialogContent className="max-w-[min(92vw,48rem)] gap-0 p-4 sm:max-w-[min(92vw,48rem)]">
                <DialogTitle className="sr-only">
                    {attachment?.name ?? 'File preview'}
                </DialogTitle>

                {attachment?.kind === 'pdf' &&
                attachment.preview_url !== null ? (
                    <figure className="flex flex-col gap-3">
                        <div className="h-[min(60dvh,36rem)] min-h-64 overflow-hidden rounded-lg border border-border bg-muted">
                            <iframe
                                src={attachment.preview_url}
                                title={attachment.name}
                                className="size-full"
                            />
                        </div>

                        <PreviewCaption attachment={attachment} />
                    </figure>
                ) : attachment !== null ? (
                    <figure className="flex flex-col items-center gap-3">
                        <img
                            src={
                                attachment.preview_url ??
                                attachment.download_url
                            }
                            alt={attachment.name}
                            className="max-h-[75vh] w-auto max-w-full rounded-lg object-contain"
                        />

                        <PreviewCaption attachment={attachment} />
                    </figure>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function PreviewCaption({ attachment }: { attachment: Attachment }) {
    return (
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
                <Download aria-hidden="true" className="size-4" />
                Download
            </a>
        </figcaption>
    );
}
