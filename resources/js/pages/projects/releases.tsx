import { Head, Link, router } from '@inertiajs/react';
import { MoreHorizontal, Package, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { ProjectHeader } from '@/components/snack/projects/project-header';
import { ReleaseDialog } from '@/components/snack/projects/release-dialog';
import {
    formatReleaseDate,
    ReleaseProgress,
    ReleaseStatus,
} from '@/components/snack/projects/release-glyphs';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
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
import {
    destroy as destroyRelease,
    show as showRelease,
} from '@/routes/projects/releases';
import {
    destroy as unrelease,
    store as markReleased,
} from '@/routes/projects/releases/released';
import type { Project, ProjectMembership, Release } from '@/types';

type Props = {
    project: Project;
    releases: Release[];
    members: ProjectMembership[];
    canContribute: boolean;
    canManage: boolean;
};

/** What the status filter can be set to. Radix reserves the empty string. */
const ALL = 'all';

/**
 * Every version of this project, as a table.
 *
 * A table rather than a stack of cards because this is a history as much as a
 * plan: the question asked here is "which versions are there, where did each one
 * get to, and when did it go out", and answering it means scanning a column
 * rather than reading a page. What is *inside* any one of them is a page
 * deeper — a row cannot hold forty cards and a description.
 *
 * Search and the status filter are applied here rather than on the server. A
 * project has tens of versions, not thousands; they are all on the page already,
 * and a round trip per keystroke would be slower than the filter it replaces.
 */
export default function ProjectReleasesPage({
    project,
    releases,
    members,
    canManage,
}: Props) {
    const workspace = useWorkspace();

    const [query, setQuery] = useState('');
    const [status, setStatus] = useState<string>(ALL);
    const [planning, setPlanning] = useState(false);
    const [editing, setEditing] = useState<Release | null>(null);

    const needle = query.trim().toLowerCase();

    const shown = releases.filter(
        (release) =>
            (status === ALL || release.state === status) &&
            (needle === '' ||
                release.name.toLowerCase().includes(needle) ||
                (release.notes ?? '').toLowerCase().includes(needle)),
    );

    return (
        <div className="flex min-h-0 w-full flex-col">
            <Head title={`${project.name} releases`} />

            <ProjectHeader
                project={project}
                workspaceSlug={workspace.slug}
                members={members}
                current="releases"
            />

            <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-5xl px-4 py-6">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative min-w-48 flex-1">
                            <Search
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                value={query}
                                placeholder="Search releases"
                                aria-label="Search releases"
                                className="pl-8"
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                        </div>

                        <Select value={status} onValueChange={setStatus}>
                            <SelectTrigger className="w-52">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    Released, unreleased
                                </SelectItem>
                                <SelectItem value="unreleased">
                                    Unreleased
                                </SelectItem>
                                <SelectItem value="released">
                                    Released
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        {canManage && (
                            <Button
                                className="ml-auto"
                                onClick={() => setPlanning(true)}
                            >
                                <Plus className="size-4" />
                                Create release
                            </Button>
                        )}
                    </div>

                    <h2 className="mt-6 text-lg font-bold tracking-tight">
                        Releases
                    </h2>

                    {releases.length === 0 ? (
                        <div className="mt-3 rounded-xl border border-border bg-muted/40 px-6 py-10 text-center">
                            <Package
                                aria-hidden="true"
                                className="mx-auto size-6 text-muted-foreground"
                            />
                            <h3 className="mt-3 text-base font-semibold">
                                No releases yet
                            </h3>
                            <p className="mt-1.5 text-sm text-muted-foreground">
                                A release is a version and the work going out in
                                it — dates, a description, and the cards it
                                carries.
                                {canManage
                                    ? ' Create one to start scheduling work into it.'
                                    : ' A project lead can create one.'}
                            </p>
                        </div>
                    ) : (
                        /*
                         * `relative` for the reason the board's strip has it:
                         * `sr-only` is absolutely positioned, and without a
                         * positioned ancestor the row menus' labels anchor to
                         * the page — which then scrolls sideways as wide as the
                         * table.
                         */
                        <div className="relative mt-2 overflow-x-auto">
                            <table className="w-full min-w-3xl border-collapse text-sm">
                                <thead>
                                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                                        <Th className="w-40">Release</Th>
                                        <Th className="w-28">Status</Th>
                                        <Th className="w-40">Progress</Th>
                                        <Th className="w-32">Start date</Th>
                                        <Th className="w-32">Release date</Th>
                                        <Th>Description</Th>
                                        <Th className="w-10">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </Th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {shown.map((release) => (
                                        <tr
                                            key={release.id}
                                            className="border-b border-border/60 transition-colors hover:bg-accent/40"
                                        >
                                            <Td>
                                                <Link
                                                    href={
                                                        showRelease([
                                                            workspace.slug,
                                                            project.slug,
                                                            release.id,
                                                        ]).url
                                                    }
                                                    className="font-medium text-brand hover:underline"
                                                >
                                                    {release.name}
                                                </Link>
                                            </Td>

                                            <Td>
                                                <ReleaseStatus
                                                    release={release}
                                                />
                                            </Td>

                                            <Td>
                                                <ReleaseProgress
                                                    done={
                                                        release.completed_count
                                                    }
                                                    total={release.task_count}
                                                />
                                            </Td>

                                            <Td className="whitespace-nowrap text-muted-foreground">
                                                {formatReleaseDate(
                                                    release.starts_on,
                                                )}
                                            </Td>

                                            <Td className="whitespace-nowrap text-muted-foreground">
                                                {formatReleaseDate(
                                                    release.ends_on,
                                                )}
                                            </Td>

                                            <Td className="max-w-0">
                                                <span className="block truncate text-muted-foreground">
                                                    {release.notes ?? ''}
                                                </span>
                                            </Td>

                                            <Td>
                                                {canManage && (
                                                    <RowActions
                                                        release={release}
                                                        workspaceSlug={
                                                            workspace.slug
                                                        }
                                                        projectSlug={
                                                            project.slug
                                                        }
                                                        onEdit={() =>
                                                            setEditing(release)
                                                        }
                                                    />
                                                )}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            {shown.length === 0 && (
                                <p className="py-8 text-center text-sm text-muted-foreground">
                                    No release matches that.
                                </p>
                            )}
                        </div>
                    )}
                </div>
            </div>

            <ReleaseDialog
                workspaceSlug={workspace.slug}
                projectSlug={project.slug}
                open={planning}
                onOpenChange={setPlanning}
            />

            {editing !== null && (
                <ReleaseDialog
                    // Keyed so the form starts from the release being edited
                    // rather than from whichever one was opened first.
                    key={editing.id}
                    release={editing}
                    workspaceSlug={workspace.slug}
                    projectSlug={project.slug}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}
        </div>
    );
}

/**
 * The row menu: edit it, ship it, or cancel it.
 */
function RowActions({
    release,
    workspaceSlug,
    projectSlug,
    onEdit,
}: {
    release: Release;
    workspaceSlug: string;
    projectSlug: string;
    onEdit: () => void;
}) {
    const args = {
        workspace: workspaceSlug,
        project: projectSlug,
        release: release.id,
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground"
                >
                    <MoreHorizontal className="size-4" />
                    <span className="sr-only">
                        More actions for {release.name}
                    </span>
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={onEdit}>Edit</DropdownMenuItem>

                {release.state === 'released' ? (
                    <DropdownMenuItem
                        onSelect={() =>
                            router.delete(unrelease.url(args), {
                                preserveScroll: true,
                            })
                        }
                    >
                        Mark as unreleased
                    </DropdownMenuItem>
                ) : (
                    <DropdownMenuItem
                        onSelect={() =>
                            router.post(
                                markReleased.url(args),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Release
                    </DropdownMenuItem>
                )}

                {/* Styled text-destructive rather than variant="destructive",
                    which paints a near-black foreground in this theme and reads
                    as disabled. */}
                <DropdownMenuItem
                    className="text-destructive"
                    onSelect={() =>
                        router.delete(destroyRelease.url(args), {
                            preserveScroll: true,
                        })
                    }
                >
                    Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function Th({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <th
            scope="col"
            className={`px-3 py-2 font-medium ${className ?? ''}`.trim()}
        >
            {children}
        </th>
    );
}

function Td({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <td className={`px-3 py-2.5 ${className ?? ''}`.trim()}>{children}</td>
    );
}
