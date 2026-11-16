import { Head, router } from '@inertiajs/react';
import { CalendarClock, ChevronDown, Play, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { BulkActionBar } from '@/components/snack/projects/bulk-action-bar';
import { ProjectHeader } from '@/components/snack/projects/project-header';
import { AgentBadge, LabelChip } from '@/components/snack/projects/task-glyphs';
import { TaskPanel } from '@/components/snack/projects/task-panel';
import {
    TaskAssigneeMenu,
    TaskPriorityMenu,
    TaskRowMenu,
    TaskStatusMenu,
    TaskTypeMenu,
} from '@/components/snack/projects/task-quick-actions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { orderedTasks, useBoard } from '@/hooks/use-board';
import { useOpenTask } from '@/hooks/use-open-task';
import {
    allSelected,
    selectedIn,
    useTaskSelection,
} from '@/hooks/use-task-selection';
import { useWorkspace } from '@/hooks/use-workspace';
import { agentStateOf, withoutReservedLabels } from '@/lib/agent-labels';
import { cn } from '@/lib/utils';
import {
    destroy as destroySprint,
    start as startSprint,
    store as storeSprint,
} from '@/routes/projects/sprints';
import type {
    BoardColumn,
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
    labels: projectLabels,
    releases,
    canContribute,
    canManage,
}: Props) {
    const workspace = useWorkspace();
    const labels = useMemo(
        () => withoutReservedLabels(projectLabels),
        [projectLabels],
    );

    const { tasks, create, update, remove, move, bulkUpdate } = useBoard({
        projectId: project.id,
        projectSlug: project.slug,
        workspaceSlug: workspace.slug,
        initialTasks,
    });

    const [dragging, setDragging] = useState<Task | null>(null);
    const openPanel = useOpenTask({ tasks, workspaceSlug: workspace.slug });
    const backlog = orderedTasks(
        tasks.filter((task) => task.sprint_id === null),
    );
    const sprintTasks = (sprintId: number) =>
        orderedTasks(tasks.filter((task) => task.sprint_id === sprintId));

    /*
     * Ticked rows, for the bulk action bar. `order` is every row as the page
     * lists them, top to bottom, so a shift-click selects the run the reader
     * sees — across sections too.
     */
    const selection = useTaskSelection();
    const order = [
        ...sprints.flatMap((sprint) => sprintTasks(sprint.id)),
        ...backlog,
    ].map((task) => task.id);
    const selectedIds = selectedIn(selection.selected, order);
    const toggleSelected = (task: Task, range: boolean) =>
        selection.toggle(task.id, { range, order });

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

    /*
     * What the row's own menus write. The same three calls the panel makes —
     * the quick actions are a second way to reach them, not a second path
     * through the server.
     */
    const updateTask = (task: Task, attributes: Record<string, unknown>) => {
        void update(task.id, attributes);
    };

    const moveTask = (
        task: Task,
        target: {
            board_column_id?: number;
            sprint_id?: number | null;
            in_backlog?: boolean;
        },
    ) => {
        void move(task, target);
    };

    const deleteTask = (task: Task) => {
        if (task.id === openPanel.openTaskId) {
            openPanel.close();
        }

        void remove(task.id);
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

            {canContribute && (
                <BulkActionBar
                    count={selectedIds.length}
                    sprints={sprints}
                    members={members}
                    agentsEnabled={project.agents_enabled}
                    onApply={(change) =>
                        bulkUpdate(
                            tasks.filter((task) =>
                                selectedIds.includes(task.id),
                            ),
                            change,
                        )
                    }
                    onClear={selection.clear}
                />
            )}

            <div className="snack-scroll min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                {sprints.map((sprint) => (
                    <BacklogSection
                        key={sprint.id}
                        title={sprint.name}
                        subtitle={sprintSubtitle(sprint)}
                        state={sprint.state}
                        tasks={sprintTasks(sprint.id)}
                        columns={columns}
                        sprints={sprints}
                        members={members}
                        labels={labels}
                        canContribute={canContribute}
                        draggingId={dragging?.id ?? null}
                        selected={selection.selected}
                        onToggleSelected={toggleSelected}
                        onToggleAll={selection.toggleAll}
                        onOpenTask={(task) => void openPanel.open(task.id)}
                        onDragStart={setDragging}
                        onDragEnd={() => setDragging(null)}
                        onDropInto={() => dropInto(sprint.id)}
                        onUpdateTask={updateTask}
                        onMoveTask={moveTask}
                        onDeleteTask={deleteTask}
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
                    sprints={sprints}
                    members={members}
                    labels={labels}
                    canContribute={canContribute}
                    draggingId={dragging?.id ?? null}
                    selected={selection.selected}
                    onToggleSelected={toggleSelected}
                    onToggleAll={selection.toggleAll}
                    onOpenTask={(task) => void openPanel.open(task.id)}
                    onDragStart={setDragging}
                    onDragEnd={() => setDragging(null)}
                    onDropInto={() => dropInto(null)}
                    onUpdateTask={updateTask}
                    onMoveTask={moveTask}
                    onDeleteTask={deleteTask}
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
                task={openPanel.selected}
                columns={columns}
                members={members}
                labels={labels}
                agentsEnabled={project.agents_enabled}
                releases={releases}
                workspaceSlug={workspace.slug}
                canContribute={canContribute}
                canDelete={canContribute}
                onClose={openPanel.close}
                onOpenTask={(task) => void openPanel.open(task.id)}
                onCreateTask={create}
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
    sprints,
    members,
    labels,
    canContribute,
    draggingId,
    selected,
    actions,
    onToggleSelected,
    onToggleAll,
    onOpenTask,
    onDragStart,
    onDragEnd,
    onDropInto,
    onCreate,
    onUpdateTask,
    onMoveTask,
    onDeleteTask,
}: {
    title: string;
    subtitle: string;
    state?: Sprint['state'];
    tasks: Task[];
    columns: BoardColumn[];
    sprints: Sprint[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    canContribute: boolean;
    draggingId: number | null;
    selected: ReadonlySet<number>;
    actions?: React.ReactNode;
    onToggleSelected: (task: Task, range: boolean) => void;
    onToggleAll: (taskIds: number[]) => void;
    onOpenTask: (task: Task) => void;
    onDragStart: (task: Task) => void;
    onDragEnd: () => void;
    onDropInto: () => void;
    onCreate: (title: string) => Promise<void>;
    onUpdateTask: (task: Task, attributes: Record<string, unknown>) => void;
    onMoveTask: (
        task: Task,
        target: {
            board_column_id?: number;
            sprint_id?: number | null;
            in_backlog?: boolean;
        },
    ) => void;
    onDeleteTask: (task: Task) => void;
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
                {canContribute && tasks.length > 0 && (
                    <Checkbox
                        checked={allSelected(
                            selected,
                            tasks.map((task) => task.id),
                        )}
                        onCheckedChange={() =>
                            onToggleAll(tasks.map((task) => task.id))
                        }
                        aria-label={`Select every task in ${title}`}
                    />
                )}

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
                            sprints={sprints}
                            members={members}
                            labels={labels}
                            canContribute={canContribute}
                            draggable={canContribute}
                            isDragging={draggingId === task.id}
                            isSelected={selected.has(task.id)}
                            onToggleSelected={onToggleSelected}
                            onOpen={onOpenTask}
                            onDragStart={onDragStart}
                            onDragEnd={onDragEnd}
                            onUpdate={onUpdateTask}
                            onMove={onMoveTask}
                            onDelete={onDeleteTask}
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

/**
 * One row, and every decision that can be made about it without opening it.
 *
 * The glyphs it already drew — type, status, priority, assignee — are the
 * controls for changing them; see {@see TaskTypeMenu} for why. Everything
 * narrower than a click still opens the card.
 */
function BacklogRow({
    task,
    columns,
    sprints,
    members,
    labels,
    canContribute,
    draggable,
    isDragging,
    isSelected,
    onToggleSelected,
    onOpen,
    onDragStart,
    onDragEnd,
    onUpdate,
    onMove,
    onDelete,
}: {
    task: Task;
    columns: BoardColumn[];
    sprints: Sprint[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    canContribute: boolean;
    draggable: boolean;
    isDragging: boolean;
    isSelected: boolean;
    onToggleSelected: (task: Task, range: boolean) => void;
    onOpen: (task: Task) => void;
    onDragStart: (task: Task) => void;
    onDragEnd: () => void;
    onUpdate: (task: Task, attributes: Record<string, unknown>) => void;
    onMove: (
        task: Task,
        target: {
            board_column_id?: number;
            sprint_id?: number | null;
            in_backlog?: boolean;
        },
    ) => void;
    onDelete: (task: Task) => void;
}) {
    const agentState = agentStateOf(task.labels);

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
                'group/row flex items-center gap-2 px-3 py-2 transition-colors hover:bg-accent/40',
                draggable && 'cursor-grab active:cursor-grabbing',
                isDragging && 'opacity-40',
                isSelected && 'bg-brand-subtle/30',
            )}
        >
            {canContribute && (
                <Checkbox
                    checked={isSelected}
                    draggable={false}
                    // Handled on click rather than onCheckedChange, which does
                    // not say whether shift was held. Space on the focused box
                    // is a click too.
                    onClick={(event) => {
                        event.preventDefault();
                        onToggleSelected(task, event.shiftKey);
                    }}
                    aria-label={`Select ${task.reference}`}
                />
            )}

            <TaskTypeMenu
                task={task}
                disabled={!canContribute}
                onChange={(type) => onUpdate(task, { type })}
            />

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

            {agentState !== null && <AgentBadge state={agentState} />}

            {withoutReservedLabels(task.labels ?? [])
                .slice(0, 2)
                .map((label) => (
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
            <TaskStatusMenu
                task={task}
                columns={columns}
                disabled={!canContribute}
                className="hidden sm:inline-flex"
                onChange={(columnId) =>
                    onMove(task, { board_column_id: columnId })
                }
            />

            <TaskPriorityMenu
                task={task}
                disabled={!canContribute}
                onChange={(priority) => onUpdate(task, { priority })}
            />

            <TaskAssigneeMenu
                task={task}
                members={members}
                disabled={!canContribute}
                onChange={(assigneeId) =>
                    onUpdate(task, { assignee_id: assigneeId })
                }
            />

            <TaskRowMenu
                task={task}
                sprints={sprints}
                labels={labels}
                canContribute={canContribute}
                canDelete={canContribute}
                onUpdate={(attributes) => onUpdate(task, attributes)}
                onMove={(target) => onMove(task, target)}
                onDelete={() => onDelete(task)}
            />
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
