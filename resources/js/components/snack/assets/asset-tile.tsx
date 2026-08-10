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
 * The thumbnail is the queue's, and it is null until the queue has been round —
 * and forever for anything that is not a decodable image. Both fall back to the
 * kind icon, which is the same control at a different fidelity rather than a
 * broken state.
 */
export function AssetTile({
    asset,
    onOpen,
}: {
    asset: LibraryAsset;
    onOpen: () => void;
}) {
    const kind = asset.file?.kind ?? 'file';
    const Icon = KIND_ICONS[kind];

    return (
        <button
            type="button"
            onClick={onOpen}
            className="group flex flex-col overflow-hidden rounded-xl border border-border bg-card text-left transition-colors hover:border-brand/50 focus-visible:border-brand focus-visible:outline-none"
        >
            <span
                className={cn(
                    'flex aspect-4/3 w-full items-center justify-center overflow-hidden bg-brand-subtle text-brand',
                    // A checkerboard behind transparent art, so a white logo on
                    // a white tile is not an empty box.
                    kind === 'image' && 'snack-checkerboard',
                )}
            >
                {asset.file?.thumbnail_url == null ? (
                    <Icon aria-hidden="true" className="size-8" />
                ) : (
                    <img
                        src={asset.file.thumbnail_url}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className="size-full object-contain transition-transform duration-200 group-hover:scale-[1.03]"
                    />
                )}
            </span>

            <span className="flex min-w-0 flex-col gap-0.5 border-t border-border px-3 py-2">
                <span className="truncate text-sm font-medium">
                    {asset.name}
                </span>

                <span className="truncate text-xs text-muted-foreground">
                    {asset.file === null
                        ? 'File missing'
                        : `${kindLabel(kind)} · ${asset.file.human_size}`}
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
