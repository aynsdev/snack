import { Head, Link, router } from '@inertiajs/react';
import { FileText, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/snack/empty-state';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { index, show, store } from '@/routes/canvases';
import type { CanvasDocument } from '@/types';

type Props = {
    canvases: CanvasDocument[];
    filters: { q: string | null };
    pagination: { next: string | null; previous: string | null };
    abilities: { create: boolean };
};

export default function CanvasIndex({
    canvases,
    filters,
    pagination,
    abilities,
}: Props) {
    const workspace = useWorkspace();
    const [term, setTerm] = useState(filters.q ?? '');
    const [creating, setCreating] = useState(false);

    const createCanvas = () => {
        setCreating(true);
        router.post(
            store.url(workspace.slug),
            {},
            {
                onFinish: () => setCreating(false),
            },
        );
    };

    const search = () => {
        router.get(
            index.url(workspace.slug),
            term.trim() === '' ? {} : { q: term.trim() },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Canvases" />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <header className="border-b border-border px-4 py-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="flex items-center gap-2 text-base font-bold text-balance">
                                <FileText
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                                Canvases
                            </h1>
                            <p className="mt-0.5 text-sm text-pretty text-muted-foreground">
                                Plans, notes, and shared knowledge for{' '}
                                {workspace.name}.
                            </p>
                        </div>

                        {abilities.create && (
                            <Button
                                size="sm"
                                disabled={creating}
                                onClick={createCanvas}
                            >
                                <Plus aria-hidden="true" />
                                {creating ? 'Creating…' : 'New canvas'}
                            </Button>
                        )}
                    </div>

                    <form
                        className="relative mt-3 max-w-lg"
                        onSubmit={(event) => {
                            event.preventDefault();
                            search();
                        }}
                    >
                        <Search
                            aria-hidden="true"
                            className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            placeholder="Search canvases"
                            aria-label="Search canvases"
                            className="h-9 pl-8"
                        />
                    </form>
                </header>

                <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6">
                    {canvases.length === 0 ? (
                        <EmptyState
                            icon={FileText}
                            title={
                                filters.q
                                    ? 'No canvases match that search'
                                    : 'Create your first canvas'
                            }
                            description={
                                filters.q
                                    ? 'Try a different title or phrase.'
                                    : 'Capture a plan, meeting notes, or knowledge your team needs to find again.'
                            }
                            action={
                                filters.q ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setTerm('');
                                            router.get(
                                                index.url(workspace.slug),
                                            );
                                        }}
                                    >
                                        Clear search
                                    </Button>
                                ) : abilities.create ? (
                                    <Button
                                        disabled={creating}
                                        onClick={createCanvas}
                                    >
                                        <Plus aria-hidden="true" />
                                        {creating ? 'Creating…' : 'New canvas'}
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
                            {canvases.map((canvas) => (
                                <li key={canvas.id}>
                                    <Link
                                        href={show({
                                            workspace: workspace.slug,
                                            canvas: canvas.id,
                                        })}
                                        className="block px-4 py-4 hover:bg-accent/50"
                                    >
                                        <div className="flex items-start gap-3">
                                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-subtle text-brand">
                                                <FileText
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            </span>

                                            <div className="min-w-0 flex-1">
                                                <h2 className="truncate text-sm font-semibold">
                                                    {canvas.title}
                                                </h2>
                                                <p className="mt-1 line-clamp-2 text-sm text-pretty text-muted-foreground">
                                                    {canvas.excerpt ||
                                                        'No content yet.'}
                                                </p>

                                                <div className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                                                    {canvas.editor && (
                                                        <UserAvatar
                                                            user={canvas.editor}
                                                            size="xs"
                                                        />
                                                    )}
                                                    <span className="truncate">
                                                        Updated by{' '}
                                                        {canvas.editor?.name ??
                                                            'a former member'}
                                                    </span>
                                                    {canvas.updated_at && (
                                                        <>
                                                            <span aria-hidden="true">
                                                                ·
                                                            </span>
                                                            <time
                                                                dateTime={
                                                                    canvas.updated_at
                                                                }
                                                            >
                                                                {formatDate(
                                                                    canvas.updated_at,
                                                                )}
                                                            </time>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}

                    {(pagination.previous || pagination.next) && (
                        <nav
                            aria-label="Canvas pages"
                            className="mt-4 flex justify-end gap-2"
                        >
                            {pagination.previous && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={pagination.previous}>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {pagination.next && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={pagination.next}>Next</Link>
                                </Button>
                            )}
                        </nav>
                    )}
                </div>
            </div>
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
