import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronRight,
    Folder,
    FolderPlus,
    Images,
    Library,
    MoreHorizontal,
    Pencil,
    Search,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AssetDetailDialog } from '@/components/snack/assets/asset-detail-dialog';
import { AssetTile } from '@/components/snack/assets/asset-tile';
import {
    AssetUploader,
    useFileDrop,
} from '@/components/snack/assets/asset-uploader';
import { FolderDialog } from '@/components/snack/assets/folder-dialog';
import { EmptyState } from '@/components/snack/empty-state';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { index as assetsIndex } from '@/routes/assets';
import {
    destroy as destroyFolder,
    show as showFolder,
} from '@/routes/assets/folders';
import type { AssetFolder, AttachmentKind, LibraryAsset } from '@/types';

type Filters = { q: string | null; kind: AttachmentKind | null };

type Props = {
    folders: AssetFolder[];
    folder: AssetFolder | null;
    breadcrumb: { id: number; name: string }[];
    assets: LibraryAsset[];
    filters: Filters;
    next_cursor: number | null;
    abilities: { create: boolean; create_folder: boolean };
};

const KIND_FILTERS: { value: AttachmentKind | null; label: string }[] = [
    { value: null, label: 'Everything' },
    { value: 'image', label: 'Images' },
    { value: 'pdf', label: 'Documents' },
    { value: 'video', label: 'Video' },
    { value: 'audio', label: 'Audio' },
    { value: 'file', label: 'Other' },
];

/**
 * The asset library.
 *
 * Where a company keeps the things it needs to be able to find again — the logo,
 * the deck, the signed contract, the one-pager — as opposed to the Files browser
 * next door, which lists everything that was ever attached to a message. The
 * difference is intent: this is what somebody *filed*, and filing is the only
 * reason anything is still findable a year later.
 *
 * # Why a gallery rather than a list
 *
 * The library's centre of gravity is brand material, and nobody recognises a
 * logo by its filename. A grid of previews answers "which one is this" in a
 * glance, where a list of rows called `Logo_FINAL_v3 (2).svg` answers nothing.
 * Documents fall back to a kind icon and lose nothing by it — a PDF is
 * recognised by its name, which the tile also shows.
 */
export default function AssetsIndex({
    folders,
    folder,
    breadcrumb,
    assets,
    filters,
    next_cursor: nextCursor,
    abilities,
}: Props) {
    const workspace = useWorkspace();
    const [term, setTerm] = useState(filters.q ?? '');
    const [creatingFolder, setCreatingFolder] = useState(false);
    const [renamingFolder, setRenamingFolder] = useState(false);
    const [openAsset, setOpenAsset] = useState<LibraryAsset | null>(null);

    const isSearching = filters.q !== null;

    /*
     * The shelves inside the one being looked at, filtered out of the flat tree
     * the page already has. Suppressed while searching: a search spans the whole
     * library — see AssetLibraryController::assets — so the subfolders of
     * whichever shelf you happened to be standing in front of are not part of
     * the answer.
     */
    const children = isSearching
        ? []
        : folders.filter((node) => node.parent_id === (folder?.id ?? null));

    /**
     * Re-run the library with a changed filter.
     *
     * A full visit rather than a fetch, so the URL carries the filter state: a
     * filtered view is something people link to, and the back button has to undo
     * a filter change like any other navigation.
     */
    const apply = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };

        router.get(
            folder === null
                ? assetsIndex.url(workspace.slug)
                : showFolder.url({
                      workspace: workspace.slug,
                      assetFolder: folder.id,
                  }),
            {
                ...(next.q === null || next.q === '' ? {} : { q: next.q }),
                ...(next.kind === null ? {} : { kind: next.kind }),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    /*
     * Dropping files anywhere over the gallery adds them to the shelf that is
     * open, which is the shelf the person is looking at. The uploader owns the
     * queue; this only hands it the files.
     */
    const enqueue = useRef<((files: File[]) => void) | null>(null);
    const { isOver, dropProps } = useFileDrop((files) =>
        enqueue.current?.(files),
    );

    /*
     * Stable, because the uploader hands the queue over from an effect keyed on
     * this callback — an inline arrow would be a new identity every render and
     * the effect would re-run on all of them. See snack's other effect-identity
     * traps: a hook keyed on a caller-rebuilt object is the same bug.
     */
    const receiveQueue = useCallback((fn: (files: File[]) => void) => {
        enqueue.current = fn;
    }, []);

    return (
        <>
            <Head title={folder?.name ?? 'Assets'} />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="border-b border-border px-4 py-3">
                    <Breadcrumb
                        workspaceSlug={workspace.slug}
                        trail={breadcrumb}
                    />

                    <div className="mt-1 flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h1 className="flex items-center gap-2 text-base font-bold tracking-tight">
                                {folder === null ? (
                                    <Library
                                        aria-hidden="true"
                                        className="size-4 text-muted-foreground"
                                    />
                                ) : (
                                    <Folder
                                        aria-hidden="true"
                                        className="size-4 text-muted-foreground"
                                    />
                                )}
                                {folder?.name ?? 'Asset library'}
                            </h1>

                            <p className="mt-0.5 max-w-prose text-sm text-pretty text-muted-foreground">
                                {folder?.description ??
                                    (folder === null
                                        ? 'Brand material, company information, and the documents everybody keeps asking for a link to.'
                                        : '')}
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            {abilities.create_folder && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setCreatingFolder(true)}
                                >
                                    <FolderPlus
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    New folder
                                </Button>
                            )}

                            {abilities.create && (
                                <AssetUploader
                                    workspaceSlug={workspace.slug}
                                    folderId={folder?.id ?? null}
                                    onReady={receiveQueue}
                                />
                            )}

                            {folder !== null && folder.can_manage && (
                                <FolderMenu
                                    workspaceSlug={workspace.slug}
                                    folder={folder}
                                    onRename={() => setRenamingFolder(true)}
                                />
                            )}
                        </div>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <form
                            className="relative min-w-56 flex-1"
                            onSubmit={(event) => {
                                event.preventDefault();
                                apply({ q: term });
                            }}
                        >
                            <Search
                                aria-hidden="true"
                                className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            />

                            <Input
                                value={term}
                                onChange={(event) =>
                                    setTerm(event.target.value)
                                }
                                placeholder="Search the whole library"
                                aria-label="Search the whole library"
                                className="h-9 pl-8"
                            />
                        </form>

                        <div className="flex flex-wrap gap-1">
                            {KIND_FILTERS.map((option) => (
                                <button
                                    key={option.label}
                                    type="button"
                                    onClick={() =>
                                        apply({ kind: option.value })
                                    }
                                    aria-pressed={filters.kind === option.value}
                                    className={cn(
                                        'rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                                        filters.kind === option.value
                                            ? 'border-brand bg-brand-subtle text-foreground'
                                            : 'border-border text-muted-foreground hover:border-brand/40',
                                    )}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {isSearching && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Searching the whole library.{' '}
                            <button
                                type="button"
                                onClick={() => {
                                    setTerm('');
                                    apply({ q: '' });
                                }}
                                className="underline underline-offset-2"
                            >
                                Clear search
                            </button>
                        </p>
                    )}
                </header>

                <div
                    {...dropProps}
                    className={cn(
                        'snack-scroll relative min-h-0 flex-1 overflow-y-auto p-4',
                        isOver && 'bg-brand-subtle/50',
                    )}
                >
                    {isOver && abilities.create && (
                        <div className="pointer-events-none absolute inset-3 z-10 flex items-center justify-center rounded-2xl border-2 border-dashed border-brand bg-background/80 text-sm font-medium">
                            Drop to add to{' '}
                            {folder?.name ?? 'the top of the library'}
                        </div>
                    )}

                    {children.length > 0 && (
                        <section className="mb-6">
                            <h2 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Folders
                            </h2>

                            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5">
                                {children.map((child) => (
                                    <li key={child.id}>
                                        <FolderCard
                                            workspaceSlug={workspace.slug}
                                            folder={child}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {assets.length === 0 ? (
                        /*
                         * Three different nothings, and they are not
                         * interchangeable. "The library is empty" beside a
                         * shelf full of folders is simply untrue — what is empty
                         * is this level of it — and the invitation to add the
                         * logo is wrong advice for somebody whose search just
                         * missed.
                         */
                        <EmptyState
                            icon={Images}
                            title={
                                isSearching || filters.kind !== null
                                    ? 'Nothing matches that'
                                    : children.length > 0
                                      ? 'No files here yet'
                                      : folder === null
                                        ? 'The library is empty'
                                        : `Nothing in ${folder.name} yet`
                            }
                            description={
                                isSearching || filters.kind !== null
                                    ? 'Try a shorter search, or clear the type filter.'
                                    : children.length > 0
                                      ? 'Open a folder above, or drop files here to file them at this level.'
                                      : 'Put the logo, the deck, and the contracts somewhere everyone can find them. Drag files here, or use Add files.'
                            }
                        />
                    ) : (
                        <>
                            {children.length > 0 && (
                                <h2 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    Files
                                </h2>
                            )}

                            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5">
                                {assets.map((asset) => (
                                    <li key={asset.id}>
                                        <AssetTile
                                            asset={asset}
                                            onOpen={() => setOpenAsset(asset)}
                                        />
                                    </li>
                                ))}
                            </ul>

                            {nextCursor !== null && (
                                <LoadMore
                                    cursor={nextCursor}
                                    filters={filters}
                                    href={
                                        folder === null
                                            ? assetsIndex.url(workspace.slug)
                                            : showFolder.url({
                                                  workspace: workspace.slug,
                                                  assetFolder: folder.id,
                                              })
                                    }
                                />
                            )}
                        </>
                    )}
                </div>
            </div>

            <FolderDialog
                workspaceSlug={workspace.slug}
                parentId={folder?.id ?? null}
                open={creatingFolder}
                onOpenChange={setCreatingFolder}
            />

            {folder !== null && (
                <FolderDialog
                    workspaceSlug={workspace.slug}
                    folder={folder}
                    open={renamingFolder}
                    onOpenChange={setRenamingFolder}
                />
            )}

            {openAsset !== null && (
                <AssetDetailDialog
                    workspaceSlug={workspace.slug}
                    asset={openAsset}
                    folders={folders}
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setOpenAsset(null);
                        }
                    }}
                />
            )}
        </>
    );
}

/**
 * Where in the tree this shelf sits, and every step back up it.
 *
 * Built from the server's own ancestry rather than walked client-side out of the
 * flat folder list: a breadcrumb that disagreed with the page it is on would be
 * a navigation to somewhere the reader is not.
 */
function Breadcrumb({
    workspaceSlug,
    trail,
}: {
    workspaceSlug: string;
    trail: { id: number; name: string }[];
}) {
    return (
        <nav
            aria-label="Breadcrumb"
            className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
        >
            <Link
                href={assetsIndex(workspaceSlug)}
                className="rounded px-1 py-0.5 transition-colors hover:bg-accent hover:text-foreground"
            >
                Library
            </Link>

            {trail.map((step, index) => (
                <span key={step.id} className="flex items-center gap-1">
                    <ChevronRight aria-hidden="true" className="size-3" />

                    {index === trail.length - 1 ? (
                        <span className="px-1 py-0.5 font-medium text-foreground">
                            {step.name}
                        </span>
                    ) : (
                        <Link
                            href={showFolder({
                                workspace: workspaceSlug,
                                assetFolder: step.id,
                            })}
                            className="rounded px-1 py-0.5 transition-colors hover:bg-accent hover:text-foreground"
                        >
                            {step.name}
                        </Link>
                    )}
                </span>
            ))}
        </nav>
    );
}

function FolderCard({
    workspaceSlug,
    folder,
}: {
    workspaceSlug: string;
    folder: AssetFolder;
}) {
    const parts = [
        folder.folder_count > 0
            ? `${folder.folder_count} folder${folder.folder_count === 1 ? '' : 's'}`
            : null,
        `${folder.asset_count} file${folder.asset_count === 1 ? '' : 's'}`,
    ].filter((part): part is string => part !== null);

    return (
        <Link
            href={showFolder({
                workspace: workspaceSlug,
                assetFolder: folder.id,
            })}
            className="flex items-center gap-3 rounded-xl border border-border bg-card px-3 py-3 transition-colors hover:border-brand/50"
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-subtle text-brand">
                <Folder aria-hidden="true" className="size-4.5" />
            </span>

            <span className="min-w-0">
                <span className="block truncate text-sm font-medium">
                    {folder.name}
                </span>

                <span className="block truncate text-xs text-muted-foreground">
                    {parts.join(' · ')}
                </span>
            </span>
        </Link>
    );
}

function FolderMenu({
    workspaceSlug,
    folder,
    onRename,
}: {
    workspaceSlug: string;
    folder: AssetFolder;
    onRename: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="ghost" size="icon">
                    <MoreHorizontal aria-hidden="true" className="size-4" />
                    <span className="sr-only">Folder actions</span>
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={onRename}>
                    <Pencil aria-hidden="true" className="size-4" />
                    Rename or move
                </DropdownMenuItem>

                {/*
                 * Absent rather than disabled when the viewer may not delete: a
                 * non-empty folder is an admin's to remove, and a greyed row
                 * with no explanation reads as the product being broken. See
                 * AssetFolderPolicy::delete.
                 */}
                {folder.can_delete && (
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() =>
                            router.delete(
                                destroyFolder({
                                    workspace: workspaceSlug,
                                    assetFolder: folder.id,
                                }).url,
                            )
                        }
                    >
                        <Trash2 aria-hidden="true" className="size-4" />
                        Delete folder
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * "Load more", as a cursor rather than a page number.
 *
 * The prop is merged server-side, so the next page appends to the grid already
 * on screen instead of replacing it — which is what makes this a continuation
 * rather than a navigation.
 */
function LoadMore({
    cursor,
    filters,
    href,
}: {
    cursor: number;
    filters: Filters;
    href: string;
}) {
    const [loading, setLoading] = useState(false);

    // A cursor from a previous filter state is meaningless; the flag resets
    // whenever a new first page arrives.
    const lastCursor = useRef(cursor);

    useEffect(() => {
        if (lastCursor.current !== cursor) {
            lastCursor.current = cursor;
            setLoading(false);
        }
    }, [cursor]);

    return (
        <div className="flex justify-center p-4">
            <Button
                variant="outline"
                size="sm"
                disabled={loading}
                onClick={() => {
                    setLoading(true);

                    router.get(
                        href,
                        {
                            before: cursor,
                            ...(filters.q === null ? {} : { q: filters.q }),
                            ...(filters.kind === null
                                ? {}
                                : { kind: filters.kind }),
                        },
                        {
                            only: ['assets', 'next_cursor'],
                            preserveState: true,
                            preserveScroll: true,
                            preserveUrl: true,
                            onFinish: () => setLoading(false),
                        },
                    );
                }}
            >
                {loading ? 'Loading…' : 'Load more'}
            </Button>
        </div>
    );
}
