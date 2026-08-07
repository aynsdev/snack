import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, Play, SquareCheckBig, X } from 'lucide-react';
import { useState } from 'react';
import { AddColumnTile } from '@/components/snack/projects/add-column-tile';
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
import { backlog as backlogRoute } from '@/routes/projects';
import { edit as settingsRoute } from '@/routes/projects/settings';
import {
    complete as completeSprint,
    start as startSprint,
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
    tasks: Task[];
    sprint: Sprint | null;
    plannedSprints: Sprint[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    epics: EpicOption[];
    releases: ReleaseOption[];
    canContribute: boolean;
    canManage: boolean;
    /**
     * How many cards are waiting in the backlog, and only when this board has no
     * sprint to draw — see {@see WaitingForSprint}. Null the rest of the time.
     */
    backlogCount: number | null;
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
    releases,
    canContribute,
    canManage,
    backlogCount,
    openTask,
}: Props) {
    const workspace = useWorkspace();

    const { tasks, create, update, remove, move, error, setError } = useBoard({
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

    /*
     * A sprint project between sprints has no board to draw.
     *
     * Everything this project holds is in the backlog or in a sprint that is
     * over, and the columns would render empty — which is indistinguishable
     * from a board that lost the work. Worse, the composer on each column would
     * file cards into the backlog, out of sight the moment the page reloaded.
     * So the columns are replaced outright rather than left empty.
     */
    const sprintless = project.uses_sprints && sprint === null;

    /*
     * Where a column added from the board lands: just left of the first Done
     * column, wherever the button that adds it happens to sit.
     *
     * A status somebody adds while looking at the board is another step before
     * finished — "To test", "Blocked", "To release" — and a column arriving to
     * the right of Done is a board that reads backwards. A board with no Done
     * column at all appends, which is the same rule with nothing to precede.
     */
    const doneAt = columns.findIndex((column) => column.category === 'done');
    const addColumnAt = doneAt === -1 ? columns.length : doneAt;

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
                {sprint !== null && (
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

            {error !== null && (
                <p className="flex items-center gap-2 border-b border-border bg-destructive/10 px-4 py-2 text-xs text-destructive">
                    {error}
                    <button
                        type="button"
                        onClick={() => setError(null)}
                        className="ml-auto rounded p-0.5 transition-colors hover:bg-destructive/10"
                    >
                        <X aria-hidden="true" className="size-3.5" />
                        <span className="sr-only">Dismiss</span>
                    </button>
                </p>
            )}

            {sprintless ? (
                <WaitingForSprint
                    project={project}
                    plannedSprints={plannedSprints}
                    backlogCount={backlogCount ?? 0}
                    canManage={canManage}
                    workspaceSlug={workspace.slug}
                />
            ) : (
                /*
                 * `relative` on the scroller is load-bearing, not decoration.
                 *
                 * Tailwind's `sr-only` is `position: absolute`, and an absolute
                 * element resolves against its nearest *positioned* ancestor —
                 * with none, that is the page itself. The screen-reader labels
                 * on the cards and column menus were therefore anchored outside
                 * this scroller, so the document grew as wide as the whole board
                 * and the window sprouted a second horizontal scrollbar under
                 * the board's own. Positioning the scroller makes it their
                 * containing block, and its overflow then clips them.
                 */
                <div className="snack-scroll relative flex min-h-0 flex-1 gap-3 overflow-x-auto p-4">
                    {columns.map((column) => (
                        <BoardColumnView
                            key={column.id}
                            column={column}
                            tasks={tasksInColumn(tasks, column.id)}
                            fields={project.card_fields}
                            canContribute={canContribute}
                            canManage={canManage}
                            workspaceSlug={workspace.slug}
                            projectSlug={project.slug}
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

                    {canManage && (
                        <AddColumnTile
                            workspaceSlug={workspace.slug}
                            projectSlug={project.slug}
                            beforeColumnId={
                                columns[addColumnAt - 1]?.id ?? null
                            }
                            afterColumnId={columns[addColumnAt]?.id ?? null}
                        />
                    )}
                </div>
            )}

            <TaskPanel
                task={selected}
                columns={columns}
                members={members}
                labels={labels}
                epics={epics}
                releases={releases}
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
 * What a sprint project shows between sprints.
 *
 * The columns are gone rather than empty, which is the whole point: three empty
 * columns over a project with thirty cards in the backlog reads as lost work,
 * and the composer they carry files cards nobody will see again until a sprint
 * starts. This says where the work is and offers the two ways out — start the
 * sprint that is planned, or stop planning in sprints at all.
 */
function WaitingForSprint({
    project,
    plannedSprints,
    backlogCount,
    canManage,
    workspaceSlug,
}: {
    project: Project;
    plannedSprints: Sprint[];
    backlogCount: number;
    canManage: boolean;
    workspaceSlug: string;
}) {
    const next = plannedSprints[0];

    return (
        <div className="snack-scroll min-h-0 flex-1 overflow-y-auto p-4">
            <div className="mx-auto max-w-md rounded-xl border border-border bg-muted/40 px-6 py-8 text-center">
                <CalendarClock
                    aria-hidden="true"
                    className="mx-auto size-6 text-muted-foreground"
                />

                <h2 className="mt-3 text-base font-semibold">
                    No sprint is running
                </h2>

                <p className="mt-1.5 text-sm text-muted-foreground">
                    This board shows the work in the active sprint.{' '}
                    {backlogCount === 0
                        ? 'Nothing has been filed yet — the backlog is where work is planned.'
                        : `${backlogCount} ${backlogCount === 1 ? 'card is' : 'cards are'} waiting in the backlog.`}
                </p>

                <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                    <Button
                        asChild
                        variant={next === undefined ? 'default' : 'outline'}
                    >
                        <Link
                            href={
                                backlogRoute([workspaceSlug, project.slug]).url
                            }
                        >
                            Open backlog
                        </Link>
                    </Button>

                    {canManage && next !== undefined && (
                        <Button
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
                    )}
                </div>

                {canManage && (
                    <p className="mt-4 text-xs text-muted-foreground">
                        Not planning in sprints? Turn them off in{' '}
                        <Link
                            href={
                                settingsRoute([workspaceSlug, project.slug]).url
                            }
                            className="underline underline-offset-2 hover:text-foreground"
                        >
                            project settings
                        </Link>{' '}
                        and every task stays on this board.
                    </p>
                )}
            </div>
        </div>
    );
}

/**
 * The running sprint, and closing it.
 *
 * Lives in the board's header rather than on the backlog because this is where
 * the sprint is *visible*: the board is what a running sprint looks like, and
 * "we are done, roll the rest forward" is a decision made looking at it. What to
 * do when nothing is running is {@see WaitingForSprint}'s, not a control here.
 */
function SprintControls({
    project,
    sprint,
    plannedSprints,
    canManage,
    workspaceSlug,
}: {
    project: Project;
    sprint: Sprint;
    plannedSprints: Sprint[];
    canManage: boolean;
    workspaceSlug: string;
}) {
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
