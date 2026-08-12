import { Link, router, usePage } from '@inertiajs/react';
import { Clock3, File, FileText, Folder, Plus, Star } from 'lucide-react';
import { useState } from 'react';
import { useIsCanvasesView, useIsFilesView } from '@/hooks/use-active-channel';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    index as canvasesIndex,
    store as storeCanvas,
} from '@/routes/canvases';
import {
    destroy as unstarCanvas,
    store as starCanvas,
} from '@/routes/canvases/star';
import { index as filesIndex } from '@/routes/files';
import { destroy as unstarFile, store as starFile } from '@/routes/files/star';
import type { FileCanvasSidebarContent, FileCanvasSidebarItem } from '@/types';

export function FileCanvasSidebar({ onNavigate }: { onNavigate?: () => void }) {
    const workspace = useWorkspace();
    const isFiles = useIsFilesView();
    const isCanvases = useIsCanvasesView();
    const sidebar = usePage<{
        file_canvas_sidebar?: FileCanvasSidebarContent;
    }>().props.file_canvas_sidebar ?? { recent: [], starred: [] };
    const [creating, setCreating] = useState(false);

    const createCanvas = () => {
        setCreating(true);
        router.post(
            storeCanvas.url(workspace.slug),
            {},
            { onFinish: () => setCreating(false) },
        );
    };

    return (
        <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
            <header className="flex h-16 shrink-0 items-center justify-between gap-3 px-4">
                <h2 className="text-lg font-bold text-balance">Files</h2>

                {!workspace.abilities.is_guest && (
                    <button
                        type="button"
                        aria-label="Create canvas"
                        title="Create canvas"
                        disabled={creating}
                        onClick={createCanvas}
                        className="flex size-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
                    >
                        <Plus aria-hidden="true" className="size-5" />
                    </button>
                )}
            </header>

            <nav
                aria-label="Files and canvases"
                className="snack-scroll flex-1 overflow-y-auto px-3 pb-4"
            >
                <ul className="space-y-0.5">
                    <FileSidebarLink
                        href={filesIndex(workspace.slug)}
                        icon={Folder}
                        label="All files"
                        active={isFiles}
                        onNavigate={onNavigate}
                    />
                    <FileSidebarLink
                        href={canvasesIndex(workspace.slug)}
                        icon={FileText}
                        label="Canvases"
                        active={isCanvases}
                        onNavigate={onNavigate}
                    />
                </ul>

                {sidebar.recent.length > 0 && (
                    <section className="mt-5 border-t border-sidebar-border pt-4">
                        <h3 className="flex items-center gap-2 px-2 text-sm font-medium text-muted-foreground">
                            <Clock3 aria-hidden="true" className="size-4" />
                            Recent
                        </h3>

                        <ul className="mt-2 space-y-0.5">
                            {sidebar.recent.map((item) => (
                                <SidebarItem
                                    key={item.id}
                                    item={item}
                                    onNavigate={onNavigate}
                                />
                            ))}
                        </ul>
                    </section>
                )}

                {sidebar.starred.length > 0 && (
                    <section className="mt-5 border-t border-sidebar-border pt-4">
                        <h3 className="flex items-center gap-2 px-2 text-sm font-medium text-muted-foreground">
                            <Star aria-hidden="true" className="size-4" />
                            Starred
                        </h3>

                        <ul className="mt-2 space-y-0.5">
                            {sidebar.starred.map((item) => (
                                <SidebarItem
                                    key={item.id}
                                    item={item}
                                    onNavigate={onNavigate}
                                />
                            ))}
                        </ul>
                    </section>
                )}
            </nav>
        </div>
    );
}

function SidebarItem({
    item,
    onNavigate,
}: {
    item: FileCanvasSidebarItem;
    onNavigate?: () => void;
}) {
    const Icon = item.type === 'canvas' ? FileText : File;
    const workspace = useWorkspace();
    const [updatingStar, setUpdatingStar] = useState(false);
    const content = (
        <>
            <Icon aria-hidden="true" className="size-4 shrink-0" />
            <span className="truncate">{item.label}</span>
        </>
    );
    const className =
        'flex min-w-0 flex-1 items-center gap-2 rounded-l-lg py-1.5 pl-2 text-sm text-muted-foreground group-hover:text-sidebar-foreground';

    const toggleStar = () => {
        setUpdatingStar(true);

        const url =
            item.type === 'canvas'
                ? (item.is_starred ? unstarCanvas : starCanvas).url([
                      workspace.slug,
                      String(item.target_id),
                  ])
                : (item.is_starred ? unstarFile : starFile).url([
                      workspace.slug,
                      Number(item.target_id),
                  ]);
        const options = {
            preserveScroll: true,
            onFinish: () => setUpdatingStar(false),
        };

        if (item.is_starred) {
            router.delete(url, options);
        } else {
            router.post(url, {}, options);
        }
    };

    return (
        <li className="group flex items-center rounded-lg hover:bg-sidebar-accent">
            {item.type === 'canvas' ? (
                <Link
                    href={item.url}
                    onClick={onNavigate}
                    className={className}
                >
                    {content}
                </Link>
            ) : (
                <a href={item.url} onClick={onNavigate} className={className}>
                    {content}
                </a>
            )}

            <button
                type="button"
                aria-label={`${item.is_starred ? 'Unstar' : 'Star'} ${item.label}`}
                aria-pressed={item.is_starred}
                disabled={updatingStar}
                onClick={toggleStar}
                className={cn(
                    'mr-1 flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50',
                    item.is_starred &&
                        'text-amber-500 opacity-100 hover:text-amber-400',
                )}
            >
                <Star
                    aria-hidden="true"
                    className="size-3.5"
                    fill={item.is_starred ? 'currentColor' : 'none'}
                />
            </button>
        </li>
    );
}

function FileSidebarLink({
    href,
    icon: Icon,
    label,
    active,
    onNavigate,
}: {
    href: ReturnType<typeof filesIndex>;
    icon: typeof Folder;
    label: string;
    active: boolean;
    onNavigate?: () => void;
}) {
    return (
        <li>
            <Link
                href={href}
                onClick={onNavigate}
                aria-current={active ? 'page' : undefined}
                className={cn(
                    'flex items-center gap-2 rounded-lg px-2 py-2 text-sm text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-foreground',
                    active &&
                        'bg-sidebar-primary text-sidebar-primary-foreground',
                )}
            >
                <Icon aria-hidden="true" className="size-4 shrink-0" />
                <span className="truncate">{label}</span>
            </Link>
        </li>
    );
}
