import { Head, router } from '@inertiajs/react';
import { CalendarClock, ChevronDown, Play, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ProjectHeader } from '@/components/snack/projects/project-header';
import {
    LabelChip,
    TaskPriorityIcon,
    TaskTypeIcon,
} from '@/components/snack/projects/task-glyphs';
import { TaskPanel } from '@/components/snack/projects/task-panel';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useBoard } from '@/hooks/use-board';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    destroy as destroySprint,
    start as startSprint,
    store as storeSprint,
} from '@/routes/projects/sprints';
import type {
    BoardColumn,
    EpicOption,
    Project,
    ProjectMembership,
    ReleaseOption,
    Sprint,
    Task,
    TaskLabel,
} from '@/types';

type Props = {
    project: Project;
    columns: BoardColumn[];
    sprints: Sprint[];
    tasks: Task[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    epics: EpicOption[];
    releases: ReleaseOption[];
    canContribute: boolean;
    canManage: boolean;
};

/**
 * The backlog: the sprints being planned, and everything not yet committed to
 * one.
 *
 * # Why rows rather than cards
 *
 * The board answers "where is this?" and is read by scanning columns; the
 * backlog answers "what order are we doing these in?" and is read down a single
 * list. A dense row shows twice as much of that list on a screen, and the
 * ordering — which is the whole point of the page — is a straight line rather
 * than something to reconstruct across seven columns.
 *
 * Cards move between the sections by dragging, which is how work is committed to
 * a sprint and taken back out of it.
 */
export default function ProjectBacklogPage({
    project,
    columns,
    sprints,
    tasks: initialTasks,
    members,
    labels,
    epics,
    releases,
    canContribute,
    canManage,
}: Props) {
    const workspace = useWorkspace();

    const { tasks, create, update, remove, move } = useBoard({
        projectId: project.id,
        projectSlug: project.slug,
        workspaceSlug: workspace.slug,
        initialTasks,
    });

    const [dragging, setDragging] = useState<Task | null>(null);
    const [openTaskId, setOpenTaskId] = useState<number | null>(null);

    const selected = tasks.find((task) => task.id === openTaskId) ?? null;
    const backlog = tasks.filter((task) => task.sprint_id === null);

    /**
     * Dropping onto a section moves the card into it, keeping the status it
     * already reached — which is why a sprint rollover does not throw work back
     * to the first column. See the tasks migration.
     */
    const dropInto = (sprintId: number | null) => {
        if (dragging === null) {
            return;
        }

        const card = dragging;

        setDragging(null);

        if (card.sprint_id === sprintId) {
            return;
        }

        void move(card, {
            sprint_id: sprintId,
            in_backlog: sprintId === null,
        });
    };

    return (
        <div className="flex min-h-0 w-full flex-col">
            <Head title={`${project.name} backlog`} />

            <ProjectHeader
                project={project}
                workspaceSlug={workspace.slug}
                members={members}
                current="backlog"
            >
                {canManage && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                storeSprint.url({
                                    workspace: workspace.slug,
                                    project: project.slug,
                                }),
                            )
                        }
                    >
                        <Plus className="size-4" />
                        Plan a sprint
                    </Button>
                )}
            </ProjectHeader>

            <div className="snack-scroll min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                {sprints.map((sprint) => (
                    <BacklogSection
                        key={sprint.id}
                        title={sprint.name}
                        subtitle={sprintSubtitle(sprint)}
                        state={sprint.state}
                        tasks={tasks.filter(
                            (task) => task.sprint_id === sprint.id,
                        )}
                        columns={columns}
                        canContribute={canContribute}
                        draggingId={dragging?.id ?? null}
                        onOpenTask={(task) => setOpenTaskId(task.id)}
                        onDragStart={setDragging}
                        onDragEnd={() => setDragging(null)}
                        onDropInto={() => dropInto(sprint.id)}
                        onCreate={async (title) => {
                            await create({
                                title,
                                type: 'story',
                                priority: 'medium',
                                sprint_id: sprint.id,
                            });
                        }}
                        actions={
                            canManage && (
                                <SprintActions
                                    project={project}
                                    sprint={sprint}
                                    workspaceSlug={workspace.slug}
                                />
                            )
                        }
                    />
                ))}

                <BacklogSection
                    title="Backlog"
                    subtitle={`${backlog.length} ${backlog.length === 1 ? 'item' : 'items'}`}
                    tasks={backlog}
                    columns={columns}
                    canContribute={canContribute}
                    draggingId={dragging?.id ?? null}
                    onOpenTask={(task) => setOpenTaskId(task.id)}
                    onDragStart={setDragging}
                    onDragEnd={() => setDragging(null)}
                    onDropInto={() => dropInto(null)}
                    onCreate={async (title) => {
                        await create({
                            title,
                            type: 'story',
                            priority: 'medium',
                        });
                    }}
                />
            </div>

            <TaskPanel
                task={selected}
                columns={columns}
                members={members}
                labels={labels}
                epics={epics}
                releases={releases}
                workspaceSlug={workspace.slug}
                canContribute={canContribute}
                canDelete={canContribute}
                onClose={() => setOpenTaskId(null)}
                onUpdate={update}
                onDelete={remove}
                onMoveToColumn={(task, columnId) => {
                    void move(task, { board_column_id: columnId });
                }}
            />
        </div>
    );
}

/**
 * One planning section: a sprint, or the backlog itself.
 *
 * The whole section is the drop target rather than a seam between rows —
 * committing work to a sprint is a decision about *which* sprint, and the
 * position within it is something to sort out on the board.
 */
function BacklogSection({
    title,
    subtitle,
    state,
    tasks,
    columns,
    canContribute,
    draggingId,
    actions,
    onOpenTask,
    onDragStart,
    onDragEnd,
    onDropInto,
    onCreate,
}: {
    title: string;
    subtitle: string;
    state?: Sprint['state'];
    tasks: Task[];
    columns: BoardColumn[];
    canContribute: boolean;
    draggingId: number | null;
    actions?: React.ReactNode;
    onOpenTask: (task: Task) => void;
    onDragStart: (task: Task) => void;
    onDragEnd: () => void;
    onDropInto: () => void;
    onCreate: (title: string) => Promise<void>;
}) {
    const [open, setOpen] = useState(true);
    const [isOver, setIsOver] = useState(false);
    const [composing, setComposing] = useState(false);
    const [title_, setTitle] = useState('');

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            onDragOver={(event: React.DragEvent) => {
                if (draggingId !== null) {
                    event.preventDefault();
                    setIsOver(true);
                }
            }}
            onDragLeave={() => setIsOver(false)}
            onDrop={(event: React.DragEvent) => {
                event.preventDefault();
                setIsOver(false);
                onDropInto();
            }}
            className={cn(
                'rounded-xl border transition-colors',
                isOver && draggingId !== null
                    ? 'border-brand bg-brand-subtle/30'
                    : 'border-border',
            )}
        >
            <div className="flex flex-wrap items-center gap-2 px-3 py-2">
                <CollapsibleTrigger className="flex items-center gap-1.5">
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-4 text-muted-foreground transition-transform',
                            !open && '-rotate-90',
                        )}
                    />
                    <span className="text-sm font-semibold">{title}</span>
                </CollapsibleTrigger>

                {state === 'active' && (
                    <span className="rounded-full bg-brand-subtle px-2 py-0.5 text-[0.625rem] font-semibold uppercase">
                        Active
                    </span>
                )}

                <span className="text-xs text-muted-foreground">
                    {subtitle}
                </span>

                {actions !== undefined && (
                    <div className="ml-auto flex items-center gap-1.5">
                        {actions}
                    </div>
                )}
            </div>

            <CollapsibleContent>
                <ul className="divide-y divide-border border-t border-border">
                    {tasks.length === 0 && (
                        <li className="px-3 py-4 text-center text-xs text-muted-foreground">
                            Drag work here.
                        </li>
                    )}

                    {tasks.map((task) => (
                        <BacklogRow
                            key={task.id}
                            task={task}
                            columns={columns}
                            draggable={canContribute}
                            isDragging={draggingId === task.id}
                            onOpen={onOpenTask}
                            onDragStart={onDragStart}
                            onDragEnd={onDragEnd}
                        />
                    ))}
                </ul>

                {canContribute && (
                    <div className="border-t border-border px-3 py-2">
                        {composing ? (
                            <form
                                onSubmit={async (event) => {
                                    event.preventDefault();

                                    const trimmed = title_.trim();

                                    if (trimmed === '') {
                                        return;
                                    }

                                    await onCreate(trimmed);
                                    setTitle('');
                                }}
                            >
                                <input
                                    autoFocus
                                    value={title_}
                                    onChange={(event) =>
                                        setTitle(event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        if (event.key === 'Escape') {
                                            setComposing(false);
                                        }
                                    }}
                                    onBlur={() => {
                                        if (title_.trim() === '') {
                                            setComposing(false);
                                        }
                                    }}
                                    placeholder="What needs doing?"
                                    aria-label="Task title"
                                    className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                                />
                            </form>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setComposing(true)}
                                className="flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <Plus aria-hidden="true" className="size-4" />
                                Create
                            </button>
                        )}
                    </div>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

function BacklogRow({
    task,
    columns,
    draggable,
    isDragging,
    onOpen,
    onDragStart,
    onDragEnd,
}: {
    task: Task;
    columns: BoardColumn[];
    draggable: boolean;
    isDragging: boolean;
    onOpen: (task: Task) => void;
    onDragStart: (task: Task) => void;
    onDragEnd: () => void;
}) {
    const column = columns.find(
        (candidate) => candidate.id === task.board_column_id,
    );

    return (
        <li
            draggable={draggable}
            onDragStart={(event) => {
                event.dataTransfer.setData('text/plain', String(task.id));
                event.dataTransfer.effectAllowed = 'move';
                onDragStart(task);
            }}
            onDragEnd={onDragEnd}
            className={cn(
                'flex items-center gap-2 px-3 py-2 transition-colors hover:bg-accent/40',
                draggable && 'cursor-grab active:cursor-grabbing',
                isDragging && 'opacity-40',
            )}
        >
            <TaskTypeIcon type={task.type} />

            <span className="shrink-0 text-xs font-medium text-muted-foreground tabular-nums">
                {task.reference}
            </span>

            <button
                type="button"
                onClick={() => onOpen(task)}
                className={cn(
                    'min-w-0 flex-1 truncate text-left text-sm',
                    task.is_complete && 'text-muted-foreground line-through',
                )}
            >
                {task.title}
            </button>

            {(task.labels ?? []).slice(0, 2).map((label) => (
                <LabelChip
                    key={label.id}
                    name={label.name}
                    color={label.color}
                />
            ))}

            {task.story_points !== null && (
                <span className="rounded-full bg-muted px-1.5 py-0.5 text-[0.625rem] leading-none font-semibold text-muted-foreground tabular-nums">
                    {task.story_points}
                </span>
            )}

            {/* The status is worth a word here in a way it is not on the board,
                where the column the card sits in already says it. */}
            {column !== undefined && (
                <span className="hidden shrink-0 rounded bg-muted px-1.5 py-0.5 text-[0.625rem] font-medium text-muted-foreground sm:inline">
                    {column.name}
                </span>
            )}

            <TaskPriorityIcon priority={task.priority} />

            {task.assignee != null ? (
                <UserAvatar user={task.assignee} size="xs" />
            ) : (
                <span
                    title="Unassigned"
                    className="size-5 rounded-full border border-dashed border-muted-foreground/40"
                />
            )}
        </li>
    );
}

function SprintActions({
    project,
    sprint,
    workspaceSlug,
}: {
    project: Project;
    sprint: Sprint;
    workspaceSlug: string;
}) {
    const args = {
        workspace: workspaceSlug,
        project: project.slug,
        sprint: sprint.id,
    };

    return sprint.state === 'planned' ? (
        <>
            <Button
                size="sm"
                variant="outline"
                onClick={() => router.post(startSprint.url(args))}
            >
                <Play className="size-4" />
                Start
            </Button>

            <Button
                size="sm"
                variant="ghost"
                onClick={() => router.delete(destroySprint.url(args))}
                title="Delete this sprint — its work returns to the backlog"
            >
                <Trash2 className="size-4" />
                <span className="sr-only">Delete {sprint.name}</span>
            </Button>
        </>
    ) : (
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <CalendarClock aria-hidden="true" className="size-3.5" />
            Running — complete it from the board
        </span>
    );
}

/** "12 Aug – 26 Aug", or the goal when the team wrote one. */
function sprintSubtitle(sprint: Sprint): string {
    if (sprint.goal !== null && sprint.goal !== '') {
        return sprint.goal;
    }

    if (sprint.starts_at === null || sprint.ends_at === null) {
        return 'No dates set';
    }

    const format = (value: string) =>
        new Date(value).toLocaleDateString(undefined, {
            day: 'numeric',
            month: 'short',
        });

    return `${format(sprint.starts_at)} – ${format(sprint.ends_at)}`;
}
