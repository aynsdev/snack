import {
    FileAudio,
    FileText,
    FileVideo,
    File as FileIcon,
    ImageIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { AttachmentKind, LibraryAsset } from '@/types';

export const KIND_ICONS: Record<AttachmentKind, typeof FileIcon> = {
    image: ImageIcon,
    video: FileVideo,
    audio: FileAudio,
    pdf: FileText,
    file: FileIcon,
};

/**
 * The picture to draw for an asset, or null when there is no picture to draw.
 *
 * # Why there are two URLs and the generated one is not always there
 *
 * `thumbnail_url` is the queue's work: a re-encoded, bounded-edge raster. It is
 * null for as long as the queue has not been round, and permanently null for
 * anything GD cannot decode — which includes every SVG, because SVG is not a
 * raster at all. A brand library is *mostly* SVG, so treating a null thumbnail
 * as "this file has no preview" turned the logos — the single thing the library
 * exists to hold — into grey placeholder icons.
 *
 * `preview_url` is the original, offered for any image under the inline ceiling,
 * and it is exactly right in both of those gaps: an SVG is a few kilobytes and
 * scales perfectly, and an image the queue has not reached yet is better shown
 * at full size than not shown at all.
 *
 * Shared with the timeline and the card attachments, which have always fallen
 * back this way — the gallery was the one surface that did not, which is why it
 * was the one surface with missing previews.
 */
export function assetPreviewUrl(asset: LibraryAsset): string | null {
    return asset.file?.thumbnail_url ?? asset.file?.preview_url ?? null;
}

/**
 * One thing on the shelf, as a gallery tile.
 *
 * # Why the tile opens a panel rather than downloading
 *
 * A message attachment is a thing you were handed, so clicking it downloads. A
 * library asset is a thing you are looking for, and the question you arrive with
 * is usually "is this the right one?" — which is answered by a bigger preview,
 * the note somebody left on it, and the actual filename, not by a file appearing
 * in the downloads folder. The download is one click further in, where it is
 * unambiguous.
 *
 * # Why every part of the tile has a fixed height
 *
 * A grid row is as tall as its tallest cell, and a tile that sizes to its own
 * content leaves the short ones floating with a gap underneath — which reads as
 * the tiles being misaligned rather than as one of them being taller. The button
 * fills its cell (`h-full`), the media box is a fixed ratio, and the caption is
 * pinned to the bottom (`mt-auto`) with a line count that does not depend on how
 * long anybody's filename is.
 */
export function AssetTile({
    asset,
    folderName,
    onOpen,
}: {
    asset: LibraryAsset;
    /** The shelf this is filed on, shown because the gallery is not folded. */
    folderName?: string | null;
    onOpen: () => void;
}) {
    const kind = asset.file?.kind ?? 'file';
    const Icon = KIND_ICONS[kind];
    const preview = assetPreviewUrl(asset);

    return (
        <button
            type="button"
            onClick={onOpen}
            className="group flex h-full w-full flex-col overflow-hidden rounded-xl border border-border bg-card text-left transition-colors hover:border-brand/50 focus-visible:border-brand focus-visible:outline-none"
        >
            <span
                className={cn(
                    'flex aspect-4/3 w-full shrink-0 items-center justify-center overflow-hidden bg-brand-subtle text-brand',
                    // A checkerboard behind transparent art, so a white logo on
                    // a white tile is not an empty box.
                    kind === 'image' && 'snack-checkerboard',
                )}
            >
                {preview === null ? (
                    <Icon aria-hidden="true" className="size-8" />
                ) : (
                    <img
                        src={preview}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className="size-full object-contain transition-transform duration-200 group-hover:scale-[1.03]"
                    />
                )}
            </span>

            <span className="mt-auto flex w-full min-w-0 flex-col gap-0.5 border-t border-border px-3 py-2">
                <span className="truncate text-sm font-medium">
                    {asset.name}
                </span>

                <span className="truncate text-xs text-muted-foreground">
                    {asset.file === null
                        ? 'File missing'
                        : `${kindLabel(kind)} · ${asset.file.human_size}`}
                    {folderName != null && ` · ${folderName}`}
                </span>
            </span>
        </button>
    );
}

export function kindLabel(kind: AttachmentKind): string {
    return {
        image: 'Image',
        video: 'Video',
        audio: 'Audio',
        pdf: 'PDF',
        file: 'File',
    }[kind];
}
