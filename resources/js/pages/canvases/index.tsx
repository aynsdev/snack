import { Head, Link, router } from '@inertiajs/react';
import {
    CheckSquare2,
    ChevronDown,
    ClipboardList,
    Copy,
    ExternalLink,
    FileText,
    Info,
    MoreHorizontal,
    Plus,
    Search,
    Share2,
    Star,
    Trash2,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/snack/empty-state';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { destroy, index, show, store } from '@/routes/canvases';
import {
    destroy as unstarCanvas,
    store as starCanvas,
} from '@/routes/canvases/star';
import type { CanvasDocument } from '@/types';

type CanvasScope = 'all' | 'created' | 'shared';
type CanvasSort = 'updated' | 'created' | 'title';
type CanvasTemplate =
    | 'employee-onboarding'
    | 'meeting-notes'
    | 'project-brief';

type Props = {
    canvases: CanvasDocument[];
    filters: { q: string | null; scope: CanvasScope; sort: CanvasSort };
    pagination: { next: string | null; previous: string | null };
    abilities: { create: boolean };
};

const templates: Array<{
    id: CanvasTemplate;
    title: string;
    description: string;
    preview: string;
    icon: typeof Users;
}> = [
    {
        id: 'employee-onboarding',
        title: 'Employee onboarding',
        description: 'Welcome new teammates with a clear first week.',
        preview: 'Welcome to the team',
        icon: Users,
    },
    {
        id: 'meeting-notes',
        title: 'Meeting notes',
        description: 'Keep decisions and action items together.',
        preview: 'Agenda · Decisions · Actions',
        icon: ClipboardList,
    },
    {
        id: 'project-brief',
        title: 'Project brief',
        description: 'Align the team on goals, scope, and milestones.',
        preview: 'Overview · Goals · Milestones',
        icon: CheckSquare2,
    },
];

export default function CanvasIndex({
    canvases,
    filters,
    pagination,
    abilities,
}: Props) {
    const workspace = useWorkspace();
    const [term, setTerm] = useState(filters.q ?? '');
    const [creating, setCreating] = useState(false);
    const [templatesOpen, setTemplatesOpen] = useState(true);

    const createCanvas = (template?: CanvasTemplate) => {
        setCreating(true);
        router.post(
            store.url(workspace.slug),
            template === undefined ? {} : { template },
            { onFinish: () => setCreating(false) },
        );
    };

    const visit = (
        changes: Partial<{ q: string | null; scope: CanvasScope; sort: CanvasSort }>,
    ) => {
        const next = { ...filters, q: term.trim() || null, ...changes };
        const query = Object.fromEntries(
            Object.entries(next).filter(([, value]) => value !== null),
        );

        router.get(index.url(workspace.slug), query, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="Canvases" />

            <main className="snack-scroll min-w-0 flex-1 overflow-y-auto bg-background">
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8">
                    <header className="flex flex-col gap-4">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h1 className="text-xl font-bold text-balance">
                                    Canvases
                                </h1>
                                <p className="mt-1 text-sm text-pretty text-muted-foreground">
                                    Plans, notes, and shared knowledge for {workspace.name}.
                                </p>
                            </div>

                            {abilities.create && (
                                <Button
                                    size="sm"
                                    disabled={creating}
                                    onClick={() => createCanvas()}
                                >
                                    <Plus aria-hidden="true" />
                                    {creating ? 'Creating…' : 'New canvas'}
                                </Button>
                            )}
                        </div>

                        <form
                            className="relative"
                            onSubmit={(event) => {
                                event.preventDefault();
                                visit({ q: term.trim() || null });
                            }}
                        >
                            <Search
                                aria-hidden="true"
                                className="absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                value={term}
                                onChange={(event) => setTerm(event.target.value)}
                                placeholder="Search canvases"
                                aria-label="Search canvases"
                                className="h-11 pl-10"
                            />
                        </form>
                    </header>

                    {abilities.create && (
                        <section aria-labelledby="canvas-templates-heading">
                            <button
                                type="button"
                                aria-expanded={templatesOpen}
                                onClick={() => setTemplatesOpen((open) => !open)}
                                className="flex items-center gap-1.5 rounded-md text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <ChevronDown
                                    aria-hidden="true"
                                    className={cn(
                                        'size-4 text-muted-foreground',
                                        !templatesOpen && '-rotate-90',
                                    )}
                                />
                                <span id="canvas-templates-heading">Templates</span>
                            </button>

                            {templatesOpen && (
                                <div className="mt-3 grid gap-3 md:grid-cols-3">
                                    {templates.map((template) => (
                                        <TemplateCard
                                            key={template.id}
                                            template={template}
                                            disabled={creating}
                                            onUse={() => createCanvas(template.id)}
                                        />
                                    ))}
                                </div>
                            )}
                        </section>
                    )}

                    <section aria-label="Canvas library" className="flex flex-col gap-3">
                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                            <div
                                role="group"
                                aria-label="Filter canvases"
                                className="flex flex-wrap gap-2"
                            >
                                {([
                                    ['all', 'All canvases'],
                                    ['created', 'Created by you'],
                                    ['shared', 'Shared with you'],
                                ] as const).map(([value, label]) => (
                                    <Button
                                        key={value}
                                        type="button"
                                        size="sm"
                                        variant={
                                            filters.scope === value
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={filters.scope === value}
                                        onClick={() => visit({ scope: value })}
                                    >
                                        {label}
                                    </Button>
                                ))}
                            </div>

                            <Select
                                value={filters.sort}
                                onValueChange={(value: CanvasSort) =>
                                    visit({ sort: value })
                                }
                            >
                                <SelectTrigger size="sm" aria-label="Sort canvases">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent align="end">
                                    <SelectItem value="updated">Recently updated</SelectItem>
                                    <SelectItem value="created">Recently created</SelectItem>
                                    <SelectItem value="title">Title</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {canvases.length === 0 ? (
                            <EmptyState
                                icon={FileText}
                                title={
                                    filters.q
                                        ? 'No canvases match that search'
                                        : 'No canvases in this view'
                                }
                                description={
                                    filters.q
                                        ? 'Try a different title or phrase.'
                                        : 'Create a Canvas or switch to another filter.'
                                }
                                action={
                                    filters.q ? (
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                setTerm('');
                                                visit({ q: null });
                                            }}
                                        >
                                            Clear search
                                        </Button>
                                    ) : abilities.create ? (
                                        <Button
                                            disabled={creating}
                                            onClick={() => createCanvas()}
                                        >
                                            <Plus aria-hidden="true" />
                                            New canvas
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                                {canvases.map((canvas) => (
                                    <CanvasRow
                                        key={canvas.id}
                                        canvas={canvas}
                                        workspaceSlug={workspace.slug}
                                    />
                                ))}
                            </ul>
                        )}

                        {(pagination.previous || pagination.next) && (
                            <nav
                                aria-label="Canvas pages"
                                className="flex justify-end gap-2"
                            >
                                {pagination.previous && (
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={pagination.previous}>Previous</Link>
                                    </Button>
                                )}
                                {pagination.next && (
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={pagination.next}>Next</Link>
                                    </Button>
                                )}
                            </nav>
                        )}
                    </section>
                </div>
            </main>
        </>
    );
}

function TemplateCard({
    template,
    disabled,
    onUse,
}: {
    template: (typeof templates)[number];
    disabled: boolean;
    onUse: () => void;
}) {
    const Icon = template.icon;

    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onUse}
            className="group overflow-hidden rounded-xl border border-border bg-card text-left shadow-xs hover:border-brand/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
        >
            <span className="flex h-28 flex-col justify-between bg-brand-subtle p-4 text-foreground">
                <span className="flex size-8 items-center justify-center rounded-lg bg-background/80 text-brand shadow-xs">
                    <Icon aria-hidden="true" className="size-4" />
                </span>
                <span className="text-sm font-semibold text-balance">
                    {template.preview}
                </span>
            </span>
            <span className="block p-4">
                <span className="block text-sm font-semibold text-balance">
                    {template.title}
                </span>
                <span className="mt-1 block text-xs text-pretty text-muted-foreground">
                    {template.description}
                </span>
            </span>
        </button>
    );
}

function CanvasRow({
    canvas,
    workspaceSlug,
}: {
    canvas: CanvasDocument;
    workspaceSlug: string;
}) {
    const url = show.url({ workspace: workspaceSlug, canvas: canvas.id });
    const [starred, setStarred] = useState(canvas.is_starred);
    const [starBusy, setStarBusy] = useState(false);

    const toggleStar = () => {
        setStarBusy(true);
        const route = starred ? unstarCanvas : starCanvas;
        const next = !starred;

        setStarred(next);
        const options = {
            preserveScroll: true,
            onError: () => setStarred(!next),
            onFinish: () => setStarBusy(false),
        };

        if (starred) {
            router.delete(route.url([workspaceSlug, canvas.id]), options);
        } else {
            router.post(route.url([workspaceSlug, canvas.id]), {}, options);
        }
    };

    return (
        <li className="group flex min-w-0 items-center gap-3 px-4 py-3 hover:bg-accent/40">
            <Link
                href={url}
                className="flex min-w-0 flex-1 items-center gap-3 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand-subtle text-brand">
                    <FileText aria-hidden="true" className="size-5" />
                </span>

                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">
                        {canvas.title}
                    </span>
                    <span className="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                        {canvas.container && (
                            <span className="max-w-40 truncate font-medium text-foreground">
                                {canvas.container.label}
                            </span>
                        )}
                        <span className="truncate">
                            {canvas.editor?.name ?? canvas.creator?.name ?? 'Former member'}
                        </span>
                        {canvas.updated_at && (
                            <>
                                <span aria-hidden="true">·</span>
                                <time dateTime={canvas.updated_at} className="truncate">
                                    Updated {formatRelativeDate(canvas.updated_at)}
                                </time>
                            </>
                        )}
                    </span>
                </span>
            </Link>

            {canvas.editor && <UserAvatar user={canvas.editor} size="sm" />}

            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={`${starred ? 'Unstar' : 'Star'} ${canvas.title}`}
                aria-pressed={starred}
                disabled={starBusy}
                onClick={toggleStar}
                className={cn('size-8', starred && 'text-amber-500')}
            >
                <Star
                    aria-hidden="true"
                    className="size-4"
                    fill={starred ? 'currentColor' : 'none'}
                />
            </Button>

            <CanvasActions
                canvas={canvas}
                url={url}
                workspaceSlug={workspaceSlug}
                starred={starred}
                onToggleStar={toggleStar}
            />
        </li>
    );
}

function CanvasActions({
    canvas,
    url,
    workspaceSlug,
    starred,
    onToggleStar,
}: {
    canvas: CanvasDocument;
    url: string;
    workspaceSlug: string;
    starred: boolean;
    onToggleStar: () => void;
}) {
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const absoluteUrl = () => new URL(url, window.location.origin).toString();
    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(absoluteUrl());
            toast.success('Canvas link copied.');
        } catch {
            toast.error('Canvas link could not be copied.');
        }
    };
    const share = async () => {
        if (navigator.share) {
            try {
                await navigator.share({
                    title: canvas.title,
                    url: absoluteUrl(),
                });

                return;
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {

                    return;
                }
            }
        }

        await copyLink();
    };
    const deleteCanvas = () => {
        setDeleting(true);
        router.delete(
            destroy.url({ workspace: workspaceSlug, canvas: canvas.id }),
            {
                preserveScroll: true,
                onFinish: () => setDeleting(false),
            },
        );
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`More actions for ${canvas.title}`}
                        className="size-8"
                    >
                        <MoreHorizontal aria-hidden="true" className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuItem onSelect={() => void share()}>
                        <Share2 aria-hidden="true" />
                        Share canvas
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setDetailsOpen(true)}>
                        <Info aria-hidden="true" />
                        View canvas details
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => void copyLink()}>
                        <Copy aria-hidden="true" />
                        Copy link to canvas
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={onToggleStar}>
                        <Star aria-hidden="true" />
                        {starred ? 'Remove from starred' : 'Add to starred'}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onSelect={() => window.open(url, '_blank', 'noopener,noreferrer')}
                    >
                        <ExternalLink aria-hidden="true" />
                        Open in new window
                    </DropdownMenuItem>
                    {canvas.can_delete && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => setDeleteOpen(true)}
                            >
                                <Trash2 aria-hidden="true" />
                                Delete canvas
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={detailsOpen} onOpenChange={setDetailsOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{canvas.title}</DialogTitle>
                        <DialogDescription>
                            Canvas details and location.
                        </DialogDescription>
                    </DialogHeader>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm">
                        <dt className="text-muted-foreground">Created by</dt>
                        <dd>{canvas.creator?.name ?? 'Former member'}</dd>
                        <dt className="text-muted-foreground">Location</dt>
                        <dd>{canvas.container?.label ?? 'Workspace'}</dd>
                        <dt className="text-muted-foreground">Created</dt>
                        <dd>{canvas.created_at ? formatDate(canvas.created_at) : 'Unknown'}</dd>
                        <dt className="text-muted-foreground">Updated</dt>
                        <dd>{canvas.updated_at ? formatDate(canvas.updated_at) : 'Unknown'}</dd>
                    </dl>
                </DialogContent>
            </Dialog>

            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent role="alertdialog">
                    <DialogHeader>
                        <DialogTitle>Delete “{canvas.title}”?</DialogTitle>
                        <DialogDescription>
                            This permanently deletes the Canvas and its cover image. This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={deleting}
                            onClick={() => setDeleteOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={deleting}
                            onClick={deleteCanvas}
                        >
                            {deleting ? 'Deleting…' : 'Delete canvas'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(value));
}

function formatRelativeDate(value: string): string {
    const date = new Date(value);
    const today = new Date();

    if (date.toDateString() === today.toDateString()) {
        return 'today';
    }

    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);

    return date.toDateString() === yesterday.toDateString()
        ? 'yesterday'
        : formatDate(value);
}
