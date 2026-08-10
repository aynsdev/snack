import { usePage } from '@inertiajs/react';
import { FolderPlus } from 'lucide-react';
import { useState } from 'react';
import { FolderDialog } from '@/components/snack/assets/folder-dialog';
import { FolderTree } from '@/components/snack/assets/folder-tree';
import { useActiveAssetFolderId } from '@/hooks/use-active-channel';
import { useWorkspace } from '@/hooks/use-workspace';
import type { AssetFolder } from '@/types';

/**
 * The library's shelves, as the workspace sidebar.
 *
 * # Why this reads the page's own props
 *
 * Every other sidebar shape is built from `snack`, the shared props that ride on
 * every response — because the channel list has to be correct on a project page,
 * a settings page, and everywhere else. The folder tree does not: it is only
 * ever rendered beside the library, and it is already on that page's props
 * because the gallery needs the same tree for its move dialog.
 *
 * Putting it in the shared props instead would mean every request in the
 * application — every message send, every read receipt — paying for a query
 * about a section most of them are nowhere near.
 *
 * The read is guarded rather than cast, because "the page has no folders prop"
 * is a real state: a full reload onto a URL this sidebar does not belong beside
 * would otherwise throw inside the shell, which takes the whole page with it
 * rather than just the column that was wrong.
 */
export function AssetSidebar({ onNavigate }: { onNavigate?: () => void }) {
    const workspace = useWorkspace();
    const folders = useLibraryFolders();
    const activeFolderId = useActiveAssetFolderId();
    const [creating, setCreating] = useState(false);

    return (
        <>
            <div className="flex items-center justify-between gap-1 px-2 pr-1">
                <p className="py-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    Library
                </p>

                <button
                    type="button"
                    onClick={() => setCreating(true)}
                    className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-foreground"
                >
                    <FolderPlus aria-hidden="true" className="size-4" />
                    <span className="sr-only">New folder</span>
                </button>
            </div>

            <FolderTree
                workspaceSlug={workspace.slug}
                folders={folders}
                activeFolderId={activeFolderId}
                onNavigate={onNavigate}
            />

            <FolderDialog
                workspaceSlug={workspace.slug}
                parentId={activeFolderId}
                open={creating}
                onOpenChange={setCreating}
            />
        </>
    );
}

/**
 * The folder tree on the current page, or none.
 */
export function useLibraryFolders(): AssetFolder[] {
    const folders = usePage().props.folders;

    return Array.isArray(folders) ? (folders as AssetFolder[]) : [];
}
