import { Folder } from 'lucide-react';
import {
    assetPreviewUrl,
    KIND_ICONS,
    kindLabel,
} from '@/components/snack/assets/asset-tile';
import { UserAvatar } from '@/components/snack/user-avatar';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import type { LibraryAsset } from '@/types';

/**
 * One thing on the shelf, as a row.
 *
 * # What a row is for that a tile is not
 *
 * The gallery answers "which one is this" — a logo is recognised by looking at
 * it, and that is the library's usual question. It is a bad answer to a
 * different one: a folder of forty signed contracts is forty identical PDF
 * icons, and finding the right one there is reading names, dates, and who filed
 * it, all of which a tile has no room for and a row has in one line.
 *
 * So the two views are not a skin. The row keeps a thumbnail — small, because it
 * is the identifier of last resort rather than the point — and spends the space
 * it saves on the metadata the gallery has to omit.
 *
 * It opens the same panel the tile does, for the reason described on
 * {@see AssetTile}: the question people arrive with is "is this the right one",
 * and a download answers it only after it has already happened.
 */
export function AssetRow({
    asset,
    folderName,
    onOpen,
}: {
    asset: LibraryAsset;
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
            className="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-accent/40 focus-visible:bg-accent/40 focus-visible:outline-none"
        >
            <span
                className={cn(
                    'flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-brand-subtle text-brand',
                    kind === 'image' && 'snack-checkerboard',
                )}
            >
                {preview === null ? (
                    <Icon aria-hidden="true" className="size-4.5" />
                ) : (
                    <img
                        src={preview}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className="size-full object-contain"
                    />
                )}
            </span>

            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">
                    {asset.name}
                </span>

                <span className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span className="truncate">
                        {asset.file === null
                            ? 'File missing'
                            : `${kindLabel(kind)} · ${asset.file.human_size}`}
                    </span>

                    {asset.creator !== null && (
                        <>
                            <span aria-hidden="true">&middot;</span>
                            <UserAvatar user={asset.creator} size="sm" />
                            <span className="truncate">
                                {asset.creator.name}
                            </span>
                        </>
                    )}

                    {asset.created_at !== null && (
                        <>
                            <span aria-hidden="true">&middot;</span>
                            <span className="whitespace-nowrap">
                                {dayLabel(asset.created_at)}
                            </span>
                        </>
                    )}
                </span>
            </span>

            {/*
             * Where it is filed. Only worth a column now that the listing is
             * flat — before, every row on screen was in the same folder by
             * construction, and a column repeating it said nothing.
             */}
            {folderName != null && (
                <span className="hidden shrink-0 items-center gap-1.5 rounded-md bg-muted px-2 py-1 text-xs text-muted-foreground sm:flex">
                    <Folder aria-hidden="true" className="size-3.5" />
                    <span className="max-w-32 truncate">{folderName}</span>
                </span>
            )}
        </button>
    );
}
