import { Check, MoreHorizontal, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    LabelChip,
    TASK_PRIORITIES,
    TASK_TYPES,
    TaskPriorityIcon,
    TaskTypeIcon,
} from '@/components/snack/projects/task-glyphs';
import { UserAvatar } from '@/components/snack/user-avatar';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type {
    BoardColumn,
    ProjectMembership,
    Sprint,
    Task,
    TaskLabel,
    TaskPriority,
    TaskType,
} from '@/types';

/**
 * The decisions a backlog row is scanned for, made from the row itself.
 *
 * # Why the glyphs became the controls
 *
 * A backlog is read down a list and edited a field at a time: this one is a bug,
 * that one is Ada's, this one goes in the next sprint. Every one of those was a
 * round trip through the card panel — open, change one select, close — which
 * costs two clicks and a full-screen context switch per decision, and makes
 * triaging twenty rows a twenty-dialog afternoon.
 *
 * So the things the row already draws are the things it now edits. Nothing new
 * is added to the row: the type glyph, the status chip, the priority glyph and
 * the avatar were all there to be read, and each opens the menu of what it can
 * become. The row's density is unchanged, which matters — the reason the backlog
 * is rows rather than cards is how many of them fit on a screen.
 *
 * What is *not* on the row goes in the overflow menu: which sprint, the
 * estimate, the labels, and deleting. Those are either less frequent or too wide
 * to wear as a glyph.
 *
 * Everything here writes immediately, for the reason the panel does
 * ({@see TaskPanel}): a board is consulted far more than it is edited, and a
 * quick action that then asked for a confirmation would not be quick. Deleting
 * is the exception, and confirms in place.
 */
export function TaskTypeMenu({
    task,
    disabled,
    onChange,
}: {
    task: Task;
    disabled: boolean;
    onChange: (type: TaskType) => void;
}) {
    return (
        <QuickMenu
            disabled={disabled}
            label={`Change type — currently ${TASK_TYPES[task.type].label}`}
            trigger={<TaskTypeIcon type={task.type} />}
        >
            <DropdownMenuLabel className="text-xs text-muted-foreground">
                Type
            </DropdownMenuLabel>

            {Object.entries(TASK_TYPES).map(([value, meta]) => (
                <DropdownMenuItem
                    key={value}
                    onSelect={() => onChange(value as TaskType)}
                >
                    {/* Hidden from the accessible name, which the label beside
                        it already carries — the glyph would otherwise read it
                        out twice. */}
                    <span aria-hidden="true" className="inline-flex">
                        <TaskTypeIcon type={value as TaskType} />
                    </span>
                    {meta.label}
                    {task.type === value && (
                        <Check className="ml-auto size-4" />
                    )}
                </DropdownMenuItem>
            ))}
        </QuickMenu>
    );
}

export function TaskPriorityMenu({
    task,
    disabled,
    onChange,
}: {
    task: Task;
    disabled: boolean;
    onChange: (priority: TaskPriority) => void;
}) {
    return (
        <QuickMenu
            disabled={disabled}
            label={`Change priority — currently ${TASK_PRIORITIES[task.priority].label}`}
            trigger={<TaskPriorityIcon priority={task.priority} />}
        >
            <DropdownMenuLabel className="text-xs text-muted-foreground">
                Priority
            </DropdownMenuLabel>

            {Object.entries(TASK_PRIORITIES).map(([value, meta]) => (
                <DropdownMenuItem
                    key={value}
                    onSelect={() => onChange(value as TaskPriority)}
                >
                    <span aria-hidden="true" className="inline-flex">
                        <TaskPriorityIcon priority={value as TaskPriority} />
                    </span>
                    {meta.label}
                    {task.priority === value && (
                        <Check className="ml-auto size-4" />
                    )}
                </DropdownMenuItem>
            ))}
        </QuickMenu>
    );
}

/**
 * Status, which on the backlog means which board column the card sits in.
 *
 * Goes through a move rather than a save, because that is what changing a
 * column is — the server decides where in it the card lands.
 */
export function TaskStatusMenu({
    task,
    columns,
    disabled,
    className,
    onChange,
}: {
    task: Task;
    columns: BoardColumn[];
    disabled: boolean;
    className?: string;
    onChange: (columnId: number) => void;
}) {
    const column = columns.find(
        (candidate) => candidate.id === task.board_column_id,
    );

    if (column === undefined) {
        return null;
    }

    return (
        <QuickMenu
            disabled={disabled}
            className={className}
            label={`Change status — currently ${column.name}`}
            trigger={
                <span className="rounded bg-muted px-1.5 py-0.5 text-[0.625rem] font-medium text-muted-foreground">
                    {column.name}
                </span>
            }
        >
            <DropdownMenuLabel className="text-xs text-muted-foreground">
                Status
            </DropdownMenuLabel>

            {columns.map((candidate) => (
                <DropdownMenuItem
                    key={candidate.id}
                    onSelect={() => {
                        if (candidate.id !== task.board_column_id) {
                            onChange(candidate.id);
                        }
                    }}
                >
                    {candidate.name}
                    {candidate.id === task.board_column_id && (
                        <Check className="ml-auto size-4" />
                    )}
                </DropdownMenuItem>
            ))}
        </QuickMenu>
    );
}

/**
 * Who is doing it.
 *
 * The list is the project's members, not the workspace's — the same rule the
 * panel follows, and the server re-checks it on save regardless.
 */
export function TaskAssigneeMenu({
    task,
    members,
    disabled,
    onChange,
}: {
    task: Task;
    members: ProjectMembership[];
    disabled: boolean;
    onChange: (assigneeId: number | null) => void;
}) {
    const assignee = task.assignee ?? null;

    return (
        <QuickMenu
            disabled={disabled}
            label={
                assignee === null
                    ? 'Assign this task'
                    : `Change assignee — currently ${assignee.name}`
            }
            trigger={
                assignee === null ? (
                    <span
                        title="Unassigned"
                        className="block size-5 rounded-full border border-dashed border-muted-foreground/40"
                    />
                ) : (
                    <UserAvatar user={assignee} size="xs" />
                )
            }
        >
            <DropdownMenuLabel className="text-xs text-muted-foreground">
                Assignee
            </DropdownMenuLabel>

            <DropdownMenuItem onSelect={() => onChange(null)}>
                Unassigned
                {assignee === null && <Check className="ml-auto size-4" />}
            </DropdownMenuItem>

            {members.map((membership) => (
                <DropdownMenuItem
                    key={membership.id}
                    onSelect={() => onChange(membership.user.id)}
                >
                    <span aria-hidden="true" className="inline-flex">
                        <UserAvatar user={membership.user} size="xs" />
                    </span>
                    <span className="truncate">{membership.user.name}</span>
                    {assignee?.id === membership.user.id && (
                        <Check className="ml-auto size-4" />
                    )}
                </DropdownMenuItem>
            ))}
        </QuickMenu>
    );
}

/**
 * The rest of it: which sprint, the estimate, the labels, and deleting.
 *
 * Deleting confirms in place rather than closing into a dialog — the menu is
 * held open by preventing the first select, so the second click is the one that
 * means it. Closing the menu forgets the confirmation, which is the point: a
 * menu reopened later must not still be armed.
 */
export function TaskRowMenu({
    task,
    sprints,
    labels,
    canContribute,
    canDelete,
    onUpdate,
    onMove,
    onDelete,
}: {
    task: Task;
    sprints: Sprint[];
    labels: TaskLabel[];
    canContribute: boolean;
    canDelete: boolean;
    onUpdate: (attributes: Record<string, unknown>) => void;
    onMove: (target: { sprint_id: number | null; in_backlog: boolean }) => void;
    onDelete: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    if (!canContribute && !canDelete) {
        return null;
    }

    const selectedLabelIds = (task.labels ?? []).map((label) => label.id);

    // A completed sprint is history; work is never moved into one.
    const destinations = sprints.filter(
        (sprint) => sprint.state !== 'completed',
    );

    return (
        <DropdownMenu
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    setConfirmingDelete(false);
                }
            }}
        >
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    draggable={false}
                    className="inline-flex shrink-0 items-center rounded p-0.5 text-muted-foreground opacity-0 transition-opacity group-hover/row:opacity-100 focus-visible:opacity-100 data-[state=open]:opacity-100"
                >
                    <MoreHorizontal aria-hidden="true" className="size-4" />
                    <span className="sr-only">
                        More actions for {task.reference}
                    </span>
                </button>
            </DropdownMenuTrigger>

            {/* Wide enough for the whole estimate scale on one line: a wrapped
                "13" reads as a different control from the six beside it. */}
            <DropdownMenuContent align="end" className="w-64">
                {canContribute && destinations.length > 0 && (
                    <>
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Move to
                        </DropdownMenuLabel>

                        {destinations.map((sprint) => (
                            <DropdownMenuItem
                                key={sprint.id}
                                onSelect={() => {
                                    if (task.sprint_id !== sprint.id) {
                                        onMove({
                                            sprint_id: sprint.id,
                                            in_backlog: false,
                                        });
                                    }
                                }}
                            >
                                <span className="truncate">{sprint.name}</span>
                                {task.sprint_id === sprint.id && (
                                    <Check className="ml-auto size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}

                        <DropdownMenuItem
                            onSelect={() => {
                                if (task.sprint_id !== null) {
                                    onMove({
                                        sprint_id: null,
                                        in_backlog: true,
                                    });
                                }
                            }}
                        >
                            Backlog
                            {task.sprint_id === null && (
                                <Check className="ml-auto size-4" />
                            )}
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />
                    </>
                )}

                {canContribute && (
                    <>
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Estimate
                        </DropdownMenuLabel>

                        {/* Wrapped rather than stacked: seven points down a menu
                            is a scroll, and the numbers are read as a scale. */}
                        <div className="flex flex-wrap gap-1 px-2 pb-1.5">
                            {ESTIMATES.map((points) => (
                                <button
                                    key={points ?? 'none'}
                                    type="button"
                                    onClick={() => {
                                        if (points !== task.story_points) {
                                            onUpdate({ story_points: points });
                                        }

                                        setOpen(false);
                                    }}
                                    className={cn(
                                        'min-w-7 rounded border px-1.5 py-0.5 text-xs tabular-nums transition-colors',
                                        points === task.story_points
                                            ? 'border-brand bg-brand-subtle font-semibold'
                                            : 'border-border hover:bg-accent',
                                    )}
                                >
                                    {points ?? '—'}
                                </button>
                            ))}
                        </div>
                    </>
                )}

                {canContribute && labels.length > 0 && (
                    <>
                        <DropdownMenuSeparator />

                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Labels
                        </DropdownMenuLabel>

                        {labels.map((label) => {
                            const selected = selectedLabelIds.includes(
                                label.id,
                            );

                            return (
                                <DropdownMenuCheckboxItem
                                    key={label.id}
                                    checked={selected}
                                    // Kept open: labelling is rarely one label.
                                    onSelect={(event) => event.preventDefault()}
                                    onCheckedChange={() =>
                                        onUpdate({
                                            label_ids: selected
                                                ? selectedLabelIds.filter(
                                                      (id) => id !== label.id,
                                                  )
                                                : [
                                                      ...selectedLabelIds,
                                                      label.id,
                                                  ],
                                        })
                                    }
                                >
                                    <LabelChip
                                        name={label.name}
                                        color={label.color}
                                    />
                                </DropdownMenuCheckboxItem>
                            );
                        })}
                    </>
                )}

                {canDelete && (
                    <>
                        <DropdownMenuSeparator />

                        {/* Styled text-destructive rather than
                            variant="destructive", which paints a near-black
                            foreground in this theme. */}
                        <DropdownMenuItem
                            className="text-destructive"
                            onSelect={(event) => {
                                if (!confirmingDelete) {
                                    event.preventDefault();
                                    setConfirmingDelete(true);

                                    return;
                                }

                                onDelete();
                            }}
                        >
                            <Trash2 aria-hidden="true" className="size-4" />
                            {confirmingDelete
                                ? `Really delete ${task.reference}?`
                                : 'Delete task'}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The Fibonacci-ish scale every team that estimates at all uses, and "none". */
const ESTIMATES: Array<number | null> = [null, 1, 2, 3, 5, 8, 13];

/**
 * A glyph on a row that is also the button for changing it.
 *
 * `draggable={false}` matters: the row itself is a drag source, and without it
 * a press that was meant to open the menu starts dragging the card instead.
 *
 * Somebody who cannot contribute gets the glyph and no button at all, rather
 * than a disabled control — a row of dimmed affordances reads as broken.
 */
function QuickMenu({
    label,
    trigger,
    disabled,
    className,
    children,
}: {
    label: string;
    trigger: React.ReactNode;
    disabled: boolean;
    className?: string;
    children: React.ReactNode;
}) {
    if (disabled) {
        return (
            <span
                className={cn('inline-flex shrink-0 items-center', className)}
            >
                {trigger}
            </span>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    draggable={false}
                    aria-label={label}
                    className={cn(
                        'inline-flex shrink-0 items-center rounded p-0.5 transition-colors hover:bg-accent data-[state=open]:bg-accent',
                        className,
                    )}
                >
                    {trigger}
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-48">
                {children}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
