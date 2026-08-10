import { Link } from '@inertiajs/react';
import { ChevronRight, Folder, FolderOpen, Library } from 'lucide-react';
import { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import { index as assetsIndex } from '@/routes/assets';
import { show as showFolder } from '@/routes/assets/folders';
import type { AssetFolder } from '@/types';

/**
 * A shelf and everything under it, ready to render.
 *
 * Built once from the flat list the server sends — see AssetFolderResource for
 * why it is flat — rather than nested server-side, because the move dialog wants
 * the flat list too and a folder sent twice is a folder that can disagree with
 * itself.
 */
export type FolderNode = AssetFolder & { children: FolderNode[] };

/**
 * Assemble the flat folder list into a tree.
 *
 * Exported because three surfaces need the same shape — the sidebar, the
 * breadcrumb's sibling menu, and the move dialog's indented list — and three
 * copies of this would be three chances to disagree about what "under" means.
 *
 * A node whose parent is missing is treated as a root rather than dropped. That
 * cannot normally happen (the parent cascade is the database's), but a folder
 * silently vanishing from the navigation is a far worse failure than one
 * appearing at the wrong level.
 */
export function buildFolderTree(folders: AssetFolder[]): FolderNode[] {
    const nodes = new Map<number, FolderNode>(
        folders.map((folder) => [folder.id, { ...folder, children: [] }]),
    );

    const roots: FolderNode[] = [];

    for (const folder of folders) {
        const node = nodes.get(folder.id);

        if (node === undefined) {
            continue;
        }

        const parent =
            folder.parent_id === null ? undefined : nodes.get(folder.parent_id);

        if (parent === undefined) {
            roots.push(node);
        } else {
            parent.children.push(node);
        }
    }

    return roots;
}

/**
 * Every ancestor of a folder, innermost last — the ids the tree has to have
 * open for that folder to be visible.
 */
function ancestorIds(
    folders: AssetFolder[],
    folderId: number | null,
): number[] {
    const byId = new Map(folders.map((folder) => [folder.id, folder]));
    const chain: number[] = [];

    let current = folderId === null ? undefined : byId.get(folderId);

    // Bounded rather than `while`: a cycle is impossible by construction (see
    // UpdateAssetFolder) but a navigation tree is not the place to bet on it.
    for (let depth = 0; depth < 16 && current !== undefined; depth++) {
        chain.unshift(current.id);

        current =
            current.parent_id === null
                ? undefined
                : byId.get(current.parent_id);
    }

    return chain;
}

/**
 * The library's navigation: the whole shelf tree, in the sidebar.
 *
 * The tree is sent whole on every library page load, so this never fetches and
 * never shows a spinner — walking into a folder is one navigation, and the
 * column beside the gallery is already correct when the gallery arrives.
 *
 * Expansion is local state seeded from the open folder's ancestry, so arriving
 * on a deep link opens the tree down to where you are, and collapsing a branch
 * you are not in stays collapsed as you browse.
 */
export function FolderTree({
    workspaceSlug,
    folders,
    activeFolderId,
    onNavigate,
}: {
    workspaceSlug: string;
    folders: AssetFolder[];
    activeFolderId: number | null;
    onNavigate?: () => void;
}) {
    const tree = useMemo(() => buildFolderTree(folders), [folders]);
    const openPath = useMemo(
        () => ancestorIds(folders, activeFolderId),
        [folders, activeFolderId],
    );

    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());

    const toggle = (id: number) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    return (
        <div className="space-y-0.5">
            {/*
             * The top of the library is a destination in its own right, not an
             * empty header: things can sit there unfiled, and "back to the top"
             * has to be one click from anywhere in the tree.
             */}
            <Link
                href={assetsIndex(workspaceSlug)}
                onClick={onNavigate}
                aria-current={activeFolderId === null ? 'page' : undefined}
                className={cn(
                    'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors',
                    activeFolderId === null
                        ? 'bg-sidebar-accent font-semibold text-foreground'
                        : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground',
                )}
            >
                <Library aria-hidden="true" className="size-4" />
                All assets
            </Link>

            {tree.map((node) => (
                <FolderRow
                    key={node.id}
                    node={node}
                    depth={0}
                    workspaceSlug={workspaceSlug}
                    activeFolderId={activeFolderId}
                    openPath={openPath}
                    collapsed={collapsed}
                    onToggle={toggle}
                    onNavigate={onNavigate}
                />
            ))}
        </div>
    );
}

function FolderRow({
    node,
    depth,
    workspaceSlug,
    activeFolderId,
    openPath,
    collapsed,
    onToggle,
    onNavigate,
}: {
    node: FolderNode;
    depth: number;
    workspaceSlug: string;
    activeFolderId: number | null;
    openPath: number[];
    collapsed: Set<number>;
    onToggle: (id: number) => void;
    onNavigate?: () => void;
}) {
    const isActive = node.id === activeFolderId;
    const hasChildren = node.children.length > 0;

    /*
     * Open unless deliberately collapsed. A branch containing the folder you are
     * standing in cannot be collapsed shut, because the row you are on would
     * disappear from the navigation while you were looking at its contents.
     */
    const isOpen = openPath.includes(node.id) || !collapsed.has(node.id);

    return (
        <div>
            <div
                className={cn(
                    'flex items-center gap-0.5 rounded-lg pr-1 transition-colors',
                    isActive
                        ? 'bg-sidebar-accent'
                        : 'hover:bg-sidebar-accent/60',
                )}
                style={{ paddingLeft: `${depth * 0.75}rem` }}
            >
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={() => onToggle(node.id)}
                        aria-expanded={isOpen}
                        aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${node.name}`}
                        className="rounded p-0.5 text-muted-foreground hover:text-foreground"
                    >
                        <ChevronRight
                            aria-hidden="true"
                            className={cn(
                                'size-3.5 transition-transform',
                                isOpen && 'rotate-90',
                            )}
                        />
                    </button>
                ) : (
                    // A spacer, so leaf rows line up with their siblings that
                    // have a chevron rather than sitting half a character left.
                    <span aria-hidden="true" className="size-4.5 shrink-0" />
                )}

                <Link
                    href={showFolder({
                        workspace: workspaceSlug,
                        assetFolder: node.id,
                    })}
                    onClick={onNavigate}
                    aria-current={isActive ? 'page' : undefined}
                    className={cn(
                        'flex min-w-0 flex-1 items-center gap-2 py-1.5 text-sm transition-colors',
                        isActive
                            ? 'font-semibold text-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {isActive ? (
                        <FolderOpen
                            aria-hidden="true"
                            className="size-4 shrink-0"
                        />
                    ) : (
                        <Folder
                            aria-hidden="true"
                            className="size-4 shrink-0"
                        />
                    )}

                    <span className="truncate">{node.name}</span>

                    {node.asset_count > 0 && (
                        <span className="ml-auto shrink-0 text-[0.6875rem] text-muted-foreground/70 tabular-nums">
                            {node.asset_count}
                        </span>
                    )}
                </Link>
            </div>

            {hasChildren && isOpen && (
                <div>
                    {node.children.map((child) => (
                        <FolderRow
                            key={child.id}
                            node={child}
                            depth={depth + 1}
                            workspaceSlug={workspaceSlug}
                            activeFolderId={activeFolderId}
                            openPath={openPath}
                            collapsed={collapsed}
                            onToggle={onToggle}
                            onNavigate={onNavigate}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
