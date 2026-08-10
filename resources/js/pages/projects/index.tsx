import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Archive,
    ChevronDown,
    ChevronUp,
    FolderPlus,
    KanbanSquare,
    MoreHorizontal,
    Plus,
    Trash2,
    UserMinus,
    UserPlus,
} from 'lucide-react';
import { useMemo, useState } from 'react';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    destroy as destroyGroup,
    move as moveGroup,
    store as storeGroup,
    update as renameGroup,
} from '@/routes/project-groups';
import { board as boardRoute, store as storeProject } from '@/routes/projects';
import { update as assignGroup } from '@/routes/projects/group';
import {
    destroy as leaveProject,
    store as joinProject,
} from '@/routes/projects/membership';
import type { Project, ProjectGroup } from '@/types';

type Props = {
    projects: Project[];
    /** The headings, in the order they are drawn. */
    groups: ProjectGroup[];
    canCreate: boolean;
    canManageGroups: boolean;
    /** Deferred — see Projects\ProjectController::index(). */
    archived?: Project[];
};

/**
 * One heading and the projects filed under it. A null group is the leftovers.
 */
type Section = {
    group: ProjectGroup | null;
    projects: Project[];
};

/**
 * Every project in the workspace, arranged under the headings it uses.
 *
 * Not just the ones you are on: this is the page that makes a board findable,
 * and a project you cannot discover is a project people recreate. The sidebar is
 * the short list of the ones you work; this is the long one.
 *
 * A workspace with no groups gets the plain grid it has always had. Headings
 * appear the moment somebody makes one, and never before — an "Ungrouped"
 * heading over the only section on the page is a label that tells the reader
 * nothing.
 */
export default function ProjectsIndexPage({
    projects,
    groups,
    canCreate,
    canManageGroups,
    archived,
}: Props) {
    const workspace = useWorkspace();
    const [creating, setCreating] = useState(false);
    const [creatingGroup, setCreatingGroup] = useState(false);
    const [renaming, setRenaming] = useState<ProjectGroup | null>(null);
    const [showArchived, setShowArchived] = useState(false);

    const sections = useMemo(
        () => sectionsFor(projects, groups),
        [projects, groups],
    );

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

                    <div className="ml-auto flex items-center gap-2">
                        {canManageGroups && (
                            <Button
                                variant="outline"
                                onClick={() => setCreatingGroup(true)}
                            >
                                <FolderPlus className="size-4" />
                                New group
                            </Button>
                        )}

                        {canCreate && (
                            <Button onClick={() => setCreating(true)}>
                                <Plus className="size-4" />
                                New project
                            </Button>
                        )}
                    </div>
                </div>

                {projects.length === 0 && groups.length === 0 ? (
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
                    <div className="mt-6 space-y-8">
                        {sections.map((section, index) => (
                            <ProjectSection
                                key={section.group?.id ?? 'ungrouped'}
                                section={section}
                                groups={groups}
                                workspaceSlug={workspace.slug}
                                canManageGroups={canManageGroups}
                                isFirst={index === 0}
                                isLast={
                                    section.group !== null &&
                                    section.group.id ===
                                        groups[groups.length - 1]?.id
                                }
                                onRename={setRenaming}
                            />
                        ))}
                    </div>
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
                            /*
                             * Flat, whatever groups they were in. An archived
                             * project is closed work being looked up, not work
                             * being arranged, and threading it back through the
                             * headings would put empty groups on the page for
                             * every heading that has nothing archived in it.
                             */
                            <ul className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {archived.map((project) => (
                                    <li key={project.id}>
                                        <ProjectCard
                                            project={project}
                                            groups={[]}
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
                groups={groups}
            />

            <GroupNameDialog
                open={creatingGroup}
                onOpenChange={setCreatingGroup}
                workspaceSlug={workspace.slug}
            />

            {/*
             * Mounted only while a group is being renamed, and keyed on it.
             * `useForm` keeps whatever it was initialized with, so a dialog that
             * stayed mounted across two renames would open the second one
             * holding the first one's name. The key is what makes the field
             * start from the group actually being renamed.
             */}
            {renaming !== null && (
                <GroupNameDialog
                    key={renaming.id}
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setRenaming(null);
                        }
                    }}
                    workspaceSlug={workspace.slug}
                    group={renaming}
                />
            )}
        </div>
    );
}

/**
 * Projects bucketed under their headings, in the order the headings are drawn.
 *
 * Every group gets a section even when nothing is in it: an empty group is a
 * shelf somebody just made and is about to fill, and one that disappeared until
 * it had contents would look like the "New group" button had failed.
 *
 * The ungrouped section comes last and only when it has something in it, so a
 * workspace that has filed every project away is not left with a permanent
 * empty heading. When there are no groups at all it is the only section, and
 * {@see ProjectSection} draws it without a heading.
 */
function sectionsFor(projects: Project[], groups: ProjectGroup[]): Section[] {
    const sections: Section[] = groups.map((group) => ({
        group,
        projects: projects.filter(
            (project) => project.project_group_id === group.id,
        ),
    }));

    const known = new Set(groups.map((group) => group.id));

    const ungrouped = projects.filter(
        (project) =>
            project.project_group_id === null ||
            !known.has(project.project_group_id),
    );

    if (ungrouped.length > 0) {
        sections.push({ group: null, projects: ungrouped });
    }

    return sections;
}

function ProjectSection({
    section,
    groups,
    workspaceSlug,
    canManageGroups,
    isFirst,
    isLast,
    onRename,
}: {
    section: Section;
    groups: ProjectGroup[];
    workspaceSlug: string;
    canManageGroups: boolean;
    isFirst: boolean;
    isLast: boolean;
    onRename: (group: ProjectGroup) => void;
}) {
    const { group, projects } = section;

    // No groups at all means no headings: the page is the flat list it was
    // before anybody arranged it.
    const showHeading = group !== null || groups.length > 0;

    return (
        <section>
            {showHeading && (
                <div className="flex items-center gap-2 border-b border-border pb-2">
                    <h2 className="text-sm font-semibold tracking-wide uppercase">
                        {group?.name ?? 'Ungrouped'}
                    </h2>

                    <span className="text-xs text-muted-foreground">
                        {projects.length}
                    </span>

                    {group !== null && canManageGroups && (
                        <GroupMenu
                            group={group}
                            workspaceSlug={workspaceSlug}
                            canMoveUp={!isFirst}
                            canMoveDown={!isLast}
                            onRename={() => onRename(group)}
                        />
                    )}
                </div>
            )}

            {projects.length === 0 ? (
                <p className="mt-3 text-sm text-muted-foreground">
                    Nothing here yet. Move a project in from its own menu.
                </p>
            ) : (
                <ul className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {projects.map((project) => (
                        <li key={project.id}>
                            <ProjectCard
                                project={project}
                                groups={groups}
                                workspaceSlug={workspaceSlug}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * What can be done to a heading: rename it, move it, remove it.
 *
 * Removing it is not guarded by a confirmation because it destroys nothing —
 * the projects under it become ungrouped and are still on the page, which the
 * flash message says out loud.
 */
function GroupMenu({
    group,
    workspaceSlug,
    canMoveUp,
    canMoveDown,
    onRename,
}: {
    group: ProjectGroup;
    workspaceSlug: string;
    canMoveUp: boolean;
    canMoveDown: boolean;
    onRename: () => void;
}) {
    const args = { workspace: workspaceSlug, projectGroup: group.id };

    const move = (direction: 'up' | 'down') => {
        router.post(
            moveGroup.url(args),
            { direction },
            { preserveScroll: true },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="ml-auto rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <MoreHorizontal aria-hidden="true" className="size-4" />
                    <span className="sr-only">Manage {group.name}</span>
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem onSelect={onRename}>Rename</DropdownMenuItem>

                <DropdownMenuItem
                    disabled={!canMoveUp}
                    onSelect={() => move('up')}
                >
                    <ChevronUp className="size-4" />
                    Move up
                </DropdownMenuItem>

                <DropdownMenuItem
                    disabled={!canMoveDown}
                    onSelect={() => move('down')}
                >
                    <ChevronDown className="size-4" />
                    Move down
                </DropdownMenuItem>

                <DropdownMenuSeparator />

                <DropdownMenuItem
                    /*
                     * `text-destructive`, not the primitive's destructive
                     * variant: that one paints the item in
                     * --destructive-foreground, which is the colour meant to sit
                     * *on* a red background. In dark mode it is near-black, so
                     * the item read as disabled on a dark menu.
                     */
                    className="text-destructive focus:text-destructive [&_svg]:text-destructive"
                    onSelect={() =>
                        router.delete(destroyGroup.url(args), {
                            preserveScroll: true,
                        })
                    }
                >
                    <Trash2 className="size-4" />
                    Delete group
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function ProjectCard({
    project,
    groups,
    workspaceSlug,
}: {
    project: Project;
    groups: ProjectGroup[];
    workspaceSlug: string;
}) {
    /*
     * The menu is only worth drawing when there is a shelf to move to and this
     * person may edit the project — moving somebody else's project between
     * groups is a change to their project, and the server says so too.
     */
    const canMove =
        groups.length > 0 && project.abilities.manage && !project.is_archived;

    /*
     * Joining is what puts a project in your sidebar, and this list is where
     * somebody notices it is missing from theirs — the group heading here counts
     * every project in the workspace, while the sidebar counts the ones you are
     * on. Without this the difference had no explanation and no remedy.
     */
    const hasMenu =
        canMove || project.abilities.join || project.abilities.leave;

    return (
        <div className="relative h-full">
            <Link
                href={boardRoute([workspaceSlug, project.slug])}
                className={cn(
                    'flex h-full flex-col gap-2 rounded-xl border border-border p-4 transition-colors hover:border-brand/50 hover:bg-accent/40',
                    project.is_archived && 'opacity-60',
                    hasMenu && 'pr-10',
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

            {hasMenu && (
                /*
                 * A sibling of the link rather than a child of it: a button
                 * inside an anchor is invalid, and a click on it would navigate
                 * to the board on its way to opening the menu.
                 */
                <div className="absolute top-2 right-2">
                    <ProjectCardMenu
                        project={project}
                        groups={groups}
                        canMove={canMove}
                        workspaceSlug={workspaceSlug}
                    />
                </div>
            )}
        </div>
    );
}

/**
 * What can be done to a project from the list: joining it, leaving it, and
 * which heading it sits under.
 *
 * Membership comes first because it is the one thing that changes what the
 * viewer sees elsewhere — the sidebar lists the projects you are *on*, so a
 * project sitting in a group here without being in your sidebar is a project
 * you have not joined, and this is where that is fixed.
 *
 * The group is a radio group rather than a list of commands, because the
 * project is always in exactly one state and the menu should show which —
 * "Ungrouped" is one of the choices, not a separate "remove from group" verb.
 */
function ProjectCardMenu({
    project,
    groups,
    canMove,
    workspaceSlug,
}: {
    project: Project;
    groups: ProjectGroup[];
    canMove: boolean;
    workspaceSlug: string;
}) {
    // "none" rather than an empty string: a radio group compares values as
    // strings, and an empty one is indistinguishable from "nothing selected".
    const current =
        project.project_group_id === null
            ? 'none'
            : String(project.project_group_id);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                    <MoreHorizontal aria-hidden="true" className="size-4" />
                    <span className="sr-only">Actions for {project.name}</span>
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-52">
                {project.abilities.join && (
                    <DropdownMenuItem
                        onSelect={() =>
                            router.post(
                                joinProject([workspaceSlug, project.slug]),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <UserPlus aria-hidden="true" />
                        Join project
                    </DropdownMenuItem>
                )}

                {project.abilities.leave && (
                    <DropdownMenuItem
                        onSelect={() =>
                            router.delete(
                                leaveProject([workspaceSlug, project.slug]),
                                { preserveScroll: true },
                            )
                        }
                    >
                        <UserMinus aria-hidden="true" />
                        Leave project
                    </DropdownMenuItem>
                )}

                {!canMove ? null : (
                    <>
                        {(project.abilities.join ||
                            project.abilities.leave) && (
                            <DropdownMenuSeparator />
                        )}

                        <DropdownMenuLabel>Move to group</DropdownMenuLabel>
                    </>
                )}

                {canMove && (
                    <DropdownMenuRadioGroup
                        value={current}
                        onValueChange={(value) => {
                            if (value === current) {
                                return;
                            }

                            router.patch(
                                assignGroup.url([workspaceSlug, project.slug]),
                                {
                                    project_group_id:
                                        value === 'none' ? null : Number(value),
                                },
                                { preserveScroll: true },
                            );
                        }}
                    >
                        {groups.map((group) => (
                            <DropdownMenuRadioItem
                                key={group.id}
                                value={String(group.id)}
                            >
                                {group.name}
                            </DropdownMenuRadioItem>
                        ))}

                        <DropdownMenuSeparator />

                        <DropdownMenuRadioItem value="none">
                            Ungrouped
                        </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Making a heading, and renaming one.
 *
 * One dialog for both: the two differ only in which request they send, and a
 * second component would be the same field, the same validation message, and
 * the same chance of the two drifting apart.
 */
function GroupNameDialog({
    open,
    onOpenChange,
    workspaceSlug,
    group,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
    group?: ProjectGroup | null;
}) {
    const form = useForm({ name: group?.name ?? '' });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>
                    {group ? `Rename ${group.name}` : 'New group'}
                </DialogTitle>
                <DialogDescription>
                    A heading the project list is arranged under — "Platform",
                    "Client work". It holds no work of its own.
                </DialogDescription>

                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();

                        const done = {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                onOpenChange(false);
                            },
                        };

                        if (group) {
                            form.patch(
                                renameGroup.url({
                                    workspace: workspaceSlug,
                                    projectGroup: group.id,
                                }),
                                done,
                            );

                            return;
                        }

                        form.post(storeGroup.url(workspaceSlug), done);
                    }}
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="group-name">Name</Label>
                        <Input
                            id="group-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder="Platform"
                            maxLength={60}
                            autoFocus
                        />
                        {form.errors.name !== undefined && (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>

                        <Button type="submit" disabled={form.processing}>
                            {group ? 'Rename' : 'Create group'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
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
    groups,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
    groups: ProjectGroup[];
}) {
    const form = useForm({
        name: '',
        key: '',
        description: '',
        color: 'violet',
        uses_sprints: true,
        project_group_id: null as number | null,
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

                    {/*
                     * Only when there are shelves to choose from. A group picker
                     * in a workspace that has never made one is a field that
                     * explains nothing and offers nothing.
                     */}
                    {groups.length > 0 && (
                        <div className="space-y-1.5">
                            <Label htmlFor="project-group">Group</Label>
                            <select
                                id="project-group"
                                value={form.data.project_group_id ?? ''}
                                onChange={(event) =>
                                    form.setData(
                                        'project_group_id',
                                        event.target.value === ''
                                            ? null
                                            : Number(event.target.value),
                                    )
                                }
                                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                            >
                                <option value="">Ungrouped</option>
                                {groups.map((group) => (
                                    <option key={group.id} value={group.id}>
                                        {group.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

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
