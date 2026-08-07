import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Check, Pencil, Plus, Rocket, Undo2, X } from 'lucide-react';
import { useState } from 'react';
import { ProjectHeader } from '@/components/snack/projects/project-header';
import { ReleaseDialog } from '@/components/snack/projects/release-dialog';
import {
    formatReleaseDate,
    ReleaseProgress,
    ReleaseStatus,
} from '@/components/snack/projects/release-glyphs';
import { TaskTypeIcon } from '@/components/snack/projects/task-glyphs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/use-workspace';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import { index as releasesRoute } from '@/routes/projects/releases';
import {
    destroy as unrelease,
    store as markReleased,
} from '@/routes/projects/releases/released';
import { update as updateTask } from '@/routes/tasks';
import type { Project, ProjectMembership, Release, ReleaseTask } from '@/types';

type Props = {
    project: Project;
    release: Release;
    members: ProjectMembership[];
    /** Optional: every task not already in a release, fetched on demand. */
    schedulable?: Array<{ id: number; reference: string; title: string }>;
    canContribute: boolean;
    canManage: boolean;
};

/**
 * One version: its dates, its description, and the work going out in it.
 *
 * A page of its own rather than an expanded table row. This is where a release
 * is actually worked — scheduling cards into it, writing up what it contains —
 * and that is a document, not a cell.
 */
export default function ProjectReleasePage({
    project,
    release,
    members,
    schedulable,
    canContribute,
    canManage,
}: Props) {
    const workspace = useWorkspace();
    const [editing, setEditing] = useState(false);
    const [adding, setAdding] = useState(false);

    const tasks = release.tasks ?? [];

    const args = {
        workspace: workspace.slug,
        project: project.slug,
        release: release.id,
    };

    /**
     * Scheduling a card into this release, or taking it out.
     *
     * Through the card's own endpoint rather than one belonging to the release:
     * `release_id` is a field on a task, it is authorized as an edit to that
     * task, and a second way in would be a second place for that check to be
     * got wrong. The page is reloaded afterwards because what it draws — the
     * contents, the progress, the picker's remaining options — is all derived
     * on the server.
     */
    const schedule = async (taskId: number, releaseId: number | null) => {
        await api.patch(
            updateTask.url({ workspace: workspace.slug, task: taskId }),
            { release_id: releaseId },
        );

        router.reload({ only: ['release', 'schedulable'] });
    };

    return (
        <div className="flex min-h-0 w-full flex-col">
            <Head title={`${release.name} — ${project.name}`} />

            <ProjectHeader
                project={project}
                workspaceSlug={workspace.slug}
                members={members}
                current="releases"
            />

            <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                <div className="mx-auto max-w-3xl px-4 py-6">
                    <Link
                        href={releasesRoute([workspace.slug, project.slug]).url}
                        className="inline-flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft aria-hidden="true" className="size-3.5" />
                        All releases
                    </Link>

                    <div className="mt-3 flex flex-wrap items-start gap-3">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-bold tracking-tight">
                                    {release.name}
                                </h1>
                                <ReleaseStatus release={release} />
                            </div>

                            <p className="mt-1 text-sm text-muted-foreground">
                                {formatReleaseDate(release.starts_on)} →{' '}
                                {formatReleaseDate(release.ends_on)}
                                {release.released_at !== null &&
                                    ` · went out ${new Date(release.released_at).toLocaleDateString()}`}
                            </p>
                        </div>

                        {canManage && (
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setEditing(true)}
                                >
                                    <Pencil className="size-4" />
                                    Edit
                                </Button>

                                {release.state === 'released' ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.delete(unrelease.url(args), {
                                                preserveScroll: true,
                                            })
                                        }
                                    >
                                        <Undo2 className="size-4" />
                                        Mark as unreleased
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                markReleased.url(args),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Rocket className="size-4" />
                                        Release
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="mt-4 flex items-center gap-3">
                        <ReleaseProgress
                            done={release.completed_count}
                            total={release.task_count}
                            className="max-w-xs"
                        />
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {release.completed_count}/{release.task_count} done
                        </span>
                    </div>

                    {release.notes !== null && release.notes !== '' && (
                        <div className="mt-6">
                            <h2 className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
                                Description
                            </h2>
                            <p className="mt-1 text-sm whitespace-pre-wrap">
                                {release.notes}
                            </p>
                        </div>
                    )}

                    <div className="mt-6">
                        <h2 className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
                            Going out in this release
                        </h2>

                        {tasks.length === 0 ? (
                            <p className="mt-1 text-sm text-muted-foreground">
                                Nothing scheduled into {release.name} yet.
                            </p>
                        ) : (
                            <ul className="mt-1.5 divide-y divide-border">
                                {tasks.map((task) => (
                                    <ReleaseTaskRow
                                        key={task.id}
                                        task={task}
                                        canContribute={canContribute}
                                        onRemove={() =>
                                            void schedule(task.id, null)
                                        }
                                    />
                                ))}
                            </ul>
                        )}

                        {canContribute &&
                            (adding ? (
                                <div className="mt-3">
                                    <SchedulablePicker
                                        projectKey={project.key}
                                        schedulable={schedulable}
                                        onPick={(taskId) => {
                                            void schedule(taskId, release.id);
                                        }}
                                        onClose={() => setAdding(false)}
                                    />
                                </div>
                            ) : (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-3"
                                    onClick={() => {
                                        setAdding(true);

                                        // Only fetched for the person who opened
                                        // it — a project's whole unscheduled
                                        // list is a large payload to send to
                                        // every reader of a version.
                                        router.reload({
                                            only: ['schedulable'],
                                        });
                                    }}
                                >
                                    <Plus className="size-4" />
                                    Add work
                                </Button>
                            ))}
                    </div>
                </div>
            </div>

            {editing && (
                <ReleaseDialog
                    release={release}
                    workspaceSlug={workspace.slug}
                    projectSlug={project.slug}
                    open
                    onOpenChange={setEditing}
                />
            )}
        </div>
    );
}

function ReleaseTaskRow({
    task,
    canContribute,
    onRemove,
}: {
    task: ReleaseTask;
    canContribute: boolean;
    onRemove: () => void;
}) {
    return (
        <li className="flex items-center gap-2 py-1.5">
            <TaskTypeIcon type={task.type} />

            <span className="w-20 shrink-0 font-mono text-xs text-muted-foreground">
                {task.reference}
            </span>

            <span
                className={cn(
                    'min-w-0 flex-1 truncate text-sm',
                    task.is_complete && 'text-muted-foreground line-through',
                )}
            >
                {task.title}
            </span>

            {task.is_complete && (
                <Check
                    aria-label="Done"
                    className="size-4 shrink-0 text-emerald-500"
                />
            )}

            {canContribute && (
                <button
                    type="button"
                    onClick={onRemove}
                    title="Take out of this release"
                    className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                >
                    <X aria-hidden="true" className="size-3.5" />
                    <span className="sr-only">
                        Take {task.reference} out of this release
                    </span>
                </button>
            )}
        </li>
    );
}

/**
 * The work that is not in any release yet.
 *
 * Only what is unscheduled, because a card belongs to one release: offering
 * something already in 1.3.0 would mean this picker quietly moves it, which is
 * not what "add work" says.
 */
function SchedulablePicker({
    projectKey,
    schedulable,
    onPick,
    onClose,
}: {
    projectKey: string;
    schedulable?: Array<{ id: number; reference: string; title: string }>;
    onPick: (taskId: number) => void;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');

    if (schedulable === undefined) {
        return (
            <div className="space-y-2">
                <Skeleton className="h-9 w-full animate-pulse" />
                <Skeleton className="h-9 w-full animate-pulse" />
            </div>
        );
    }

    const needle = query.trim().toLowerCase();

    const matches = schedulable.filter(
        (task) =>
            needle === '' ||
            task.title.toLowerCase().includes(needle) ||
            task.reference.toLowerCase().includes(needle),
    );

    return (
        <div className="rounded-lg border border-border p-2">
            <div className="flex items-center gap-2">
                <Input
                    autoFocus
                    value={query}
                    placeholder={`Search ${projectKey} tasks`}
                    aria-label="Search unscheduled work"
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            onClose();
                        }
                    }}
                />

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onClose}
                    className="text-muted-foreground"
                >
                    Done
                </Button>
            </div>

            {matches.length === 0 ? (
                <p className="px-1 py-3 text-sm text-muted-foreground">
                    {schedulable.length === 0
                        ? 'Every task in this project is already in a release.'
                        : 'Nothing matches that.'}
                </p>
            ) : (
                <ul className="mt-1 max-h-60 overflow-y-auto">
                    {matches.slice(0, 50).map((task) => (
                        <li key={task.id}>
                            <button
                                type="button"
                                onClick={() => onPick(task.id)}
                                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-accent"
                            >
                                <span className="w-20 shrink-0 font-mono text-xs text-muted-foreground">
                                    {task.reference}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-sm">
                                    {task.title}
                                </span>
                                <Plus className="size-4 shrink-0 text-muted-foreground" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
