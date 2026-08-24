import { Head, Link, router } from '@inertiajs/react';
import {
    FileAudio,
    FileText,
    FileVideo,
    File as FileIcon,
    Folder,
    ImageIcon,
    Search,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { EmptyState } from '@/components/snack/empty-state';
import { FilePreviewDialog } from '@/components/snack/file-preview-dialog';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import { index as filesIndex } from '@/routes/files';
import type { AttachmentKind, FileRow } from '@/types';

type ChannelOption = { id: number; name: string; slug: string };

type Filters = {
    kind: AttachmentKind | null;
    channel: number | null;
    q: string | null;
};

type Props = {
    files: FileRow[];
    filters: Filters;
    channels: ChannelOption[];
    next_cursor: number | null;
};

const KIND_ICONS: Record<AttachmentKind, typeof FileIcon> = {
    image: ImageIcon,
    video: FileVideo,
    audio: FileAudio,
    pdf: FileText,
    file: FileIcon,
};

const KIND_FILTERS: { value: AttachmentKind | null; label: string }[] = [
    { value: null, label: 'Everything' },
    { value: 'image', label: 'Images' },
    { value: 'pdf', label: 'PDFs' },
    { value: 'video', label: 'Video' },
    { value: 'audio', label: 'Audio' },
    { value: 'file', label: 'Other' },
];

/**
 * Everything shared in this workspace that you are allowed to see.
 *
 * A flat, newest-first list rather than a folder tree: files here are not filed
 * anywhere, they are said in a conversation, so the useful question is "what was
 * shared recently" or "where is that spreadsheet" — which is what the filters and
 * the name search answer. A tree would be an empty hierarchy nobody maintains.
 *
 * Every row is scoped server-side to the containers the viewer can read, so this
 * is never a listing of what is in private channels — see
 * {@see \App\Http\Controllers\Files\FileBrowserController}.
 */
export default function FilesIndex({
    files,
    filters,
    channels,
    next_cursor: nextCursor,
}: Props) {
    const workspace = useWorkspace();
    const [term, setTerm] = useState(filters.q ?? '');
    const [viewing, setViewing] = useState<FileRow | null>(null);

    /**
     * Re-run the browser with a changed filter.
     *
     * A full visit rather than a fetch, so the URL carries the filter state:
     * a filtered view is something people link to and come back to, and the
     * back button has to undo a filter change like any other navigation.
     */
    const apply = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };

        router.get(
            filesIndex.url(workspace.slug),
            {
                ...(next.kind === null ? {} : { kind: next.kind }),
                ...(next.channel === null ? {} : { channel: next.channel }),
                ...(next.q === null || next.q === '' ? {} : { q: next.q }),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Files" />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="border-b border-border px-4 py-3">
                    <div className="flex items-center gap-2">
                        <Folder
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                        <h1 className="text-base font-bold tracking-tight">
                            Files
                        </h1>
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
                                placeholder="Search file names"
                                aria-label="Search file names"
                                className="h-9 pl-8"
                            />
                        </form>

                        <select
                            value={filters.channel ?? ''}
                            onChange={(event) =>
                                apply({
                                    channel:
                                        event.target.value === ''
                                            ? null
                                            : Number(event.target.value),
                                })
                            }
                            aria-label="Filter by channel"
                            className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="">All channels</option>

                            {channels.map((channel) => (
                                <option key={channel.id} value={channel.id}>
                                    #{channel.slug}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="mt-2 flex flex-wrap gap-1">
                        {KIND_FILTERS.map((option) => (
                            <button
                                key={option.label}
                                type="button"
                                onClick={() => apply({ kind: option.value })}
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
                </header>

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    {files.length === 0 ? (
                        <EmptyState
                            icon={Folder}
                            title={
                                filters.q !== null || filters.kind !== null
                                    ? 'Nothing matches those filters'
                                    : 'No files shared yet'
                            }
                            description="Files people attach to messages show up here, so you can find them again without scrolling back through the conversation."
                        />
                    ) : (
                        <>
                            <ul className="divide-y divide-border">
                                {files.map((file) => (
                                    <FileListRow
                                        key={file.id}
                                        file={file}
                                        onOpen={() => setViewing(file)}
                                    />
                                ))}
                            </ul>

                            {nextCursor !== null && (
                                <LoadMore
                                    cursor={nextCursor}
                                    filters={filters}
                                    workspaceSlug={workspace.slug}
                                />
                            )}
                        </>
                    )}
                </div>
            </div>

            <FilePreviewDialog
                file={viewing}
                open={viewing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setViewing(null);
                    }
                }}
            />
        </>
    );
}

function FileListRow({
    file,
    onOpen,
}: {
    file: FileRow;
    onOpen: () => void;
}) {
    const href = useContainerHref(file.container);
    const Icon = KIND_ICONS[file.kind];

    return (
        <li className="flex items-center gap-3 px-4 py-3 hover:bg-accent/40">
            <button
                type="button"
                onClick={onOpen}
                className="flex min-w-0 flex-1 items-center gap-3 text-left"
            >
                <span className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-brand-subtle text-brand">
                    {file.thumbnail_url === null ? (
                        <Icon aria-hidden="true" className="size-5" />
                    ) : (
                        <img
                            src={file.thumbnail_url}
                            alt=""
                            loading="lazy"
                            decoding="async"
                            className="size-full object-cover"
                        />
                    )}
                </span>

                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {file.name}
                    </span>

                    <span className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                        {file.uploader !== null && (
                            <>
                                <UserAvatar user={file.uploader} size="sm" />
                                <span className="truncate">
                                    {file.uploader.name}
                                </span>
                                <span aria-hidden="true">&middot;</span>
                            </>
                        )}

                        <span>{file.human_size}</span>

                        {file.created_at !== null && (
                            <>
                                <span aria-hidden="true">&middot;</span>
                                <span>{dayLabel(file.created_at)}</span>
                            </>
                        )}
                    </span>
                </span>
            </button>

            {/*
             * A separate link from the preview, and deliberately so: "open the
             * file" and "go to where it was said" are different intentions, and
             * a row that does one when you wanted the other is worse than two
             * targets.
             */}
            {href !== null && (
                <Link
                    href={href}
                    className="flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <ContainerLabel container={file.container} />
                </Link>
            )}
        </li>
    );
}

/**
 * "Load more", as a cursor rather than a page number.
 *
 * The prop is merged server-side, so the next page appends to the list already
 * on screen instead of replacing it — which is what makes this a continuation
 * rather than a navigation.
 */
function LoadMore({
    cursor,
    filters,
    workspaceSlug,
}: {
    cursor: number;
    filters: Filters;
    workspaceSlug: string;
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
                        filesIndex.url(workspaceSlug),
                        {
                            before: cursor,
                            ...(filters.kind === null
                                ? {}
                                : { kind: filters.kind }),
                            ...(filters.channel === null
                                ? {}
                                : { channel: filters.channel }),
                            ...(filters.q === null ? {} : { q: filters.q }),
                        },
                        {
                            only: ['files', 'next_cursor'],
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
