import { Head, router } from '@inertiajs/react';
import { CalendarClock, Play, SquareCheckBig } from 'lucide-react';
import { useState } from 'react';
import { BoardColumnView } from '@/components/snack/projects/board-column-view';
import { ProjectHeader } from '@/components/snack/projects/project-header';
import { TaskPanel } from '@/components/snack/projects/task-panel';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dropNeighbours, tasksInColumn, useBoard } from '@/hooks/use-board';
import { useWorkspace } from '@/hooks/use-workspace';
import {
    complete as completeSprint,
    start as startSprint,
} from '@/routes/projects/sprints';
import type {
    BoardColumn,
    EpicOption,
    Project,
    ProjectMembership,
    Sprint,
    Task,
    TaskLabel,
} from '@/types';

type Props = {
    project: Project;
    columns: BoardColumn[];
    tasks: Task[];
    sprint: Sprint | null;
    plannedSprints: Sprint[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    epics: EpicOption[];
    canContribute: boolean;
    canManage: boolean;
    /** A card named by `?task=PWA-1499`, opened on arrival. */
    openTask: Task | null;
};

/**
 * The board.
 *
 * Columns scroll horizontally and each column scrolls vertically inside it, so
 * the header and the tabs stay put on a board eight columns wide — the shell is
 * `h-dvh overflow-hidden`, and a page that let the body scroll would take the
 * project's own navigation off the top of the screen with it.
 */
export default function ProjectBoardPage({
    project,
    columns,
    tasks: initialTasks,
    sprint,
    plannedSprints,
    members,
    labels,
    epics,
    canContribute,
    canManage,
    openTask,
}: Props) {
    const workspace = useWorkspace();

    const { tasks, create, update, remove, move } = useBoard({
        projectId: project.id,
        projectSlug: project.slug,
        workspaceSlug: workspace.slug,
        initialTasks,
    });

    const [dragging, setDragging] = useState<Task | null>(null);

    /*
     * Which card is open, held as an id rather than as the task itself.
     *
     * The panel then reads that id out of the live list, so an edit made here —
     * or arriving over the socket from somebody else — redraws the open panel.
     * Holding the object would pin it to whatever the card looked like when it
     * was clicked.
     */
    const [openTaskId, setOpenTaskId] = useState<number | null>(
        openTask?.id ?? null,
    );

    const selected = tasks.find((task) => task.id === openTaskId) ?? null;

    const handleDrop = (columnId: number, index: number) => {
        if (dragging === null) {
            return;
        }

        const destination = tasksInColumn(tasks, columnId);
        const card = dragging;

        setDragging(null);

        void move(card, {
            board_column_id: columnId,
            ...dropNeighbours(destination, card.id, index),
        });
    };

    return (
        <div className="flex min-h-0 w-full flex-col">
            <Head title={`${project.name} board`} />

            <ProjectHeader
                project={project}
                workspaceSlug={workspace.slug}
                members={members}
                current="board"
            >
                {project.uses_sprints && (
                    <SprintControls
                        project={project}
                        sprint={sprint}
                        plannedSprints={plannedSprints}
                        canManage={canManage}
                        workspaceSlug={workspace.slug}
                    />
                )}
            </ProjectHeader>

            {!canContribute && (
                <p className="border-b border-border bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                    You are reading this board. Join the project to file and
                    move work on it.
                </p>
            )}

            <div className="snack-scroll flex min-h-0 flex-1 gap-3 overflow-x-auto p-4">
                {columns.map((column) => (
                    <BoardColumnView
                        key={column.id}
                        column={column}
                        tasks={tasksInColumn(tasks, column.id)}
                        fields={project.card_fields}
                        canContribute={canContribute}
                        draggingId={dragging?.id ?? null}
                        onOpenTask={(task) => setOpenTaskId(task.id)}
                        onDragStart={setDragging}
                        onDragEnd={() => setDragging(null)}
                        onDrop={handleDrop}
                        onCreate={async (columnId, title) => {
                            await create({
                                title,
                                type: 'story',
                                priority: 'medium',
                                board_column_id: columnId,
                                // Filed straight onto the board, which means
                                // into the sprint the board is showing —
                                // otherwise the card would vanish into the
                                // backlog the moment it was written.
                                sprint_id: sprint?.id ?? null,
                            });
                        }}
                    />
                ))}
            </div>

            <TaskPanel
                task={selected}
                columns={columns}
                members={members}
                labels={labels}
                epics={epics}
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
 * Starting the next sprint, and closing the one that is running.
 *
 * Lives in the board's header rather than on the backlog because this is where
 * the sprint is *visible*: the board is what a running sprint looks like, and
 * "we are done, roll the rest forward" is a decision made looking at it.
 */
function SprintControls({
    project,
    sprint,
    plannedSprints,
    canManage,
    workspaceSlug,
}: {
    project: Project;
    sprint: Sprint | null;
    plannedSprints: Sprint[];
    canManage: boolean;
    workspaceSlug: string;
}) {
    if (sprint !== null) {
        return (
            <div className="flex items-center gap-2">
                <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-subtle px-2.5 py-1 text-xs font-medium">
                    <CalendarClock aria-hidden="true" className="size-3.5" />
                    {sprint.name}
                    {sprint.is_overdue && (
                        <span className="text-rose-500">· overdue</span>
                    )}
                </span>

                {canManage && (
                    <CompleteSprintButton
                        project={project}
                        sprint={sprint}
                        plannedSprints={plannedSprints}
                        workspaceSlug={workspaceSlug}
                    />
                )}
            </div>
        );
    }

    if (!canManage) {
        return (
            <span className="text-xs text-muted-foreground">
                No sprint is running.
            </span>
        );
    }

    const next = plannedSprints[0];

    return next === undefined ? (
        <span className="text-xs text-muted-foreground">
            Plan a sprint in the backlog to fill this board.
        </span>
    ) : (
        <Button
            size="sm"
            onClick={() =>
                router.post(
                    startSprint.url({
                        workspace: workspaceSlug,
                        project: project.slug,
                        sprint: next.id,
                    }),
                )
            }
        >
            <Play className="size-4" />
            Start {next.name}
        </Button>
    );
}

/**
 * Closing a sprint, and saying where the unfinished work goes.
 *
 * The destination is asked for rather than assumed, because the two answers mean
 * different things: rolling into the next sprint is "we still intend to do
 * this", and returning to the backlog is "we will decide later". Silently
 * picking one would make the sprint report say something the team did not.
 */
function CompleteSprintButton({
    project,
    sprint,
    plannedSprints,
    workspaceSlug,
}: {
    project: Project;
    sprint: Sprint;
    plannedSprints: Sprint[];
    workspaceSlug: string;
}) {
    const [open, setOpen] = useState(false);
    const [destination, setDestination] = useState(BACKLOG);

    return (
        <>
            <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                <SquareCheckBig className="size-4" />
                Complete sprint
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogTitle>Complete {sprint.name}</DialogTitle>
                    <DialogDescription>
                        Anything not in a Done column moves on. Finished work
                        stays where it is.
                    </DialogDescription>

                    <div className="space-y-1.5">
                        <p className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
                            Move unfinished work to
                        </p>

                        <Select
                            value={destination}
                            onValueChange={setDestination}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={BACKLOG}>
                                    The backlog
                                </SelectItem>

                                {plannedSprints.map((planned) => (
                                    <SelectItem
                                        key={planned.id}
                                        value={String(planned.id)}
                                    >
                                        {planned.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>

                        <Button
                            onClick={() => {
                                router.post(
                                    completeSprint.url({
                                        workspace: workspaceSlug,
                                        project: project.slug,
                                        sprint: sprint.id,
                                    }),
                                    {
                                        move_to_sprint_id:
                                            destination === BACKLOG
                                                ? null
                                                : Number(destination),
                                    },
                                    { onSuccess: () => setOpen(false) },
                                );
                            }}
                        >
                            Complete sprint
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** Radix Select reserves the empty string, so "no sprint" needs a sentinel. */
const BACKLOG = 'backlog';
