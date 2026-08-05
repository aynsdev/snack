import { Head, Link, useForm } from '@inertiajs/react';
import { Archive, KanbanSquare, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/snack/empty-state';
import {
    PROJECT_COLOR_NAMES,
    PROJECT_COLORS,
    ProjectAvatar,
} from '@/components/snack/projects/project-avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { board as boardRoute, store as storeProject } from '@/routes/projects';
import type { Project } from '@/types';

type Props = {
    projects: Project[];
    canCreate: boolean;
    /** Deferred — see Projects\ProjectController::index(). */
    archived?: Project[];
};

/**
 * Every project in the workspace.
 *
 * Not just the ones you are on: this is the page that makes a board findable,
 * and a project you cannot discover is a project people recreate. The sidebar is
 * the short list of the ones you work; this is the long one.
 */
export default function ProjectsIndexPage({
    projects,
    canCreate,
    archived,
}: Props) {
    const workspace = useWorkspace();
    const [creating, setCreating] = useState(false);
    const [showArchived, setShowArchived] = useState(false);

    return (
        <div className="snack-scroll w-full overflow-y-auto">
            <Head title="Projects" />

            <div className="mx-auto max-w-5xl px-4 py-6">
                <div className="flex flex-wrap items-center gap-3">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight">
                            Projects
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Boards, backlogs, and who is working what.
                        </p>
                    </div>

                    {canCreate && (
                        <Button
                            className="ml-auto"
                            onClick={() => setCreating(true)}
                        >
                            <Plus className="size-4" />
                            New project
                        </Button>
                    )}
                </div>

                {projects.length === 0 ? (
                    <div className="mt-10">
                        <EmptyState
                            icon={KanbanSquare}
                            title="No projects yet"
                            description={
                                canCreate
                                    ? 'A project is a board, a backlog, and the people on them. Make the first one.'
                                    : 'Nobody has created a project in this workspace yet.'
                            }
                        />
                    </div>
                ) : (
                    <ul className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <ProjectCard
                                    project={project}
                                    workspaceSlug={workspace.slug}
                                />
                            </li>
                        ))}
                    </ul>
                )}

                <div className="mt-10">
                    <button
                        type="button"
                        onClick={() => setShowArchived((current) => !current)}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <Archive aria-hidden="true" className="size-4" />
                        {showArchived ? 'Hide' : 'Show'} archived projects
                    </button>

                    {showArchived &&
                        /*
                         * The prop arrives after the first paint — it is
                         * deferred — so the skeleton is what stands in for it.
                         * `archived` being undefined means "still loading",
                         * which is why the empty case is checked separately.
                         */
                        (archived === undefined ? (
                            <div className="mt-3 space-y-2">
                                <Skeleton className="h-16 w-full animate-pulse" />
                                <Skeleton className="h-16 w-full animate-pulse" />
                            </div>
                        ) : archived.length === 0 ? (
                            <p className="mt-3 text-sm text-muted-foreground">
                                Nothing has been archived.
                            </p>
                        ) : (
                            <ul className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {archived.map((project) => (
                                    <li key={project.id}>
                                        <ProjectCard
                                            project={project}
                                            workspaceSlug={workspace.slug}
                                        />
                                    </li>
                                ))}
                            </ul>
                        ))}
                </div>
            </div>

            <CreateProjectDialog
                open={creating}
                onOpenChange={setCreating}
                workspaceSlug={workspace.slug}
            />
        </div>
    );
}

function ProjectCard({
    project,
    workspaceSlug,
}: {
    project: Project;
    workspaceSlug: string;
}) {
    return (
        <Link
            href={boardRoute([workspaceSlug, project.slug])}
            className={cn(
                'flex h-full flex-col gap-2 rounded-xl border border-border p-4 transition-colors hover:border-brand/50 hover:bg-accent/40',
                project.is_archived && 'opacity-60',
            )}
        >
            <div className="flex items-center gap-2.5">
                <ProjectAvatar project={project} size="lg" />

                <div className="min-w-0">
                    <p className="truncate font-semibold">{project.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {project.key}
                        {project.is_member && ' · you are on this'}
                    </p>
                </div>
            </div>

            {project.description !== null && (
                <p className="line-clamp-2 text-sm text-muted-foreground">
                    {project.description}
                </p>
            )}

            <p className="mt-auto text-xs text-muted-foreground">
                {project.member_count ?? 0}{' '}
                {project.member_count === 1 ? 'member' : 'members'} ·{' '}
                {project.task_count ?? 0}{' '}
                {project.task_count === 1 ? 'task' : 'tasks'} ·{' '}
                {project.uses_sprints ? 'Sprints' : 'Kanban'}
            </p>
        </Link>
    );
}

/**
 * Making a project.
 *
 * The key is offered rather than demanded: left blank, the server derives one
 * from the name — "Pedicab Web Api" becomes PWA — which is what somebody would
 * have typed anyway. It is shown because it ends up inside every reference the
 * team quotes, and finding that out later is worse than choosing it now.
 */
function CreateProjectDialog({
    open,
    onOpenChange,
    workspaceSlug,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
}) {
    const form = useForm({
        name: '',
        key: '',
        description: '',
        color: 'violet',
        uses_sprints: true,
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>New project</DialogTitle>
                <DialogDescription>
                    A board and a backlog. You can change any of this later.
                </DialogDescription>

                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();

                        form.post(storeProject.url(workspaceSlug), {
                            onSuccess: () => {
                                form.reset();
                                onOpenChange(false);
                            },
                        });
                    }}
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="project-name">Name</Label>
                        <Input
                            id="project-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder="Pedicab Web API"
                            autoFocus
                        />
                        {form.errors.name !== undefined && (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="project-key">Key</Label>
                        <Input
                            id="project-key"
                            value={form.data.key}
                            onChange={(event) =>
                                form.setData(
                                    'key',
                                    event.target.value.toUpperCase(),
                                )
                            }
                            placeholder="Derived from the name"
                            maxLength={10}
                            className="uppercase"
                        />
                        <p className="text-xs text-muted-foreground">
                            The prefix on every task — PWA-1, PWA-2.
                        </p>
                        {form.errors.key !== undefined && (
                            <p className="text-xs text-destructive">
                                {form.errors.key}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label>Colour</Label>
                        <div className="flex flex-wrap gap-2">
                            {PROJECT_COLOR_NAMES.map((color) => (
                                <button
                                    key={color}
                                    type="button"
                                    aria-label={color}
                                    aria-pressed={form.data.color === color}
                                    onClick={() => form.setData('color', color)}
                                    className={cn(
                                        'size-6 rounded-md transition-transform',
                                        PROJECT_COLORS[color],
                                        form.data.color === color &&
                                            'ring-2 ring-foreground ring-offset-2 ring-offset-background',
                                    )}
                                />
                            ))}
                        </div>
                    </div>

                    <label className="flex items-start gap-2.5">
                        <Checkbox
                            checked={form.data.uses_sprints}
                            onCheckedChange={(checked) =>
                                form.setData('uses_sprints', checked === true)
                            }
                        />
                        <span>
                            <span className="text-sm font-medium">
                                Plan in sprints
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Off means kanban: one board, one backlog, no
                                fortnights.
                            </span>
                        </span>
                    </label>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>

                        <Button type="submit" disabled={form.processing}>
                            Create project
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
