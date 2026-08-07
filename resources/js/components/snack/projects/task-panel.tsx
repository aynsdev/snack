import { Check, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import {
    LabelChip,
    TASK_PRIORITIES,
    TASK_TYPES,
    TaskPriorityIcon,
    TaskTypeIcon,
} from '@/components/snack/projects/task-glyphs';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type {
    BoardColumn,
    EpicOption,
    ProjectMembership,
    ReleaseOption,
    Task,
    TaskLabel,
    TaskPriority,
    TaskType,
} from '@/types';

/**
 * A card, opened.
 *
 * # Saving
 *
 * Every control here writes on change rather than on a Save button. A board is
 * consulted more than it is edited: somebody opens a card to reassign it, or to
 * bump its priority, and then closes it — a form that made them confirm turns
 * one decision into two clicks and leaves the ones who forget with a card that
 * silently kept its old value.
 *
 * The two free-text fields are the exception. They save on blur, because saving
 * per keystroke would be a request per letter, and a title half-typed is not a
 * title anybody meant.
 *
 * # The assignee list is the project's members
 *
 * Not the workspace's. That is the rule the whole feature turns on — people are
 * assigned work on the projects they are on — and the server re-checks it on
 * save, so this list is a convenience rather than the enforcement.
 */
export function TaskPanel({
    task,
    columns,
    members,
    labels,
    epics,
    releases,
    canContribute,
    canDelete,
    onClose,
    onUpdate,
    onDelete,
    onMoveToColumn,
}: {
    task: Task | null;
    columns: BoardColumn[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    epics: EpicOption[];
    releases: ReleaseOption[];
    canContribute: boolean;
    canDelete: boolean;
    onClose: () => void;
    onUpdate: (
        taskId: number,
        attributes: Record<string, unknown>,
    ) => Promise<unknown>;
    onDelete: (taskId: number) => Promise<void>;
    onMoveToColumn: (task: Task, columnId: number) => void;
}) {
    return (
        <Dialog
            open={task !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-h-[90dvh] w-[min(52rem,95vw)] max-w-none overflow-y-auto sm:max-w-none">
                {task !== null && (
                    <TaskPanelBody
                        /*
                         * Keyed on the task, so every field below remounts with
                         * the new card's values when a different one is opened.
                         * Without it the local drafts held by the text fields
                         * would follow the reader from card to card — the
                         * `useState(prop)` trap, which freezes at first render.
                         */
                        key={task.id}
                        task={task}
                        columns={columns}
                        members={members}
                        labels={labels}
                        epics={epics}
                        releases={releases}
                        canContribute={canContribute}
                        canDelete={canDelete}
                        onUpdate={onUpdate}
                        onDelete={onDelete}
                        onMoveToColumn={onMoveToColumn}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function TaskPanelBody({
    task,
    columns,
    members,
    labels,
    epics,
    releases,
    canContribute,
    canDelete,
    onUpdate,
    onDelete,
    onMoveToColumn,
    onClose,
}: {
    task: Task;
    columns: BoardColumn[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    epics: EpicOption[];
    releases: ReleaseOption[];
    canContribute: boolean;
    canDelete: boolean;
    onUpdate: (
        taskId: number,
        attributes: Record<string, unknown>,
    ) => Promise<unknown>;
    onDelete: (taskId: number) => Promise<void>;
    onMoveToColumn: (task: Task, columnId: number) => void;
    onClose: () => void;
}) {
    const [title, setTitle] = useState(task.title);
    const [description, setDescription] = useState(task.description ?? '');
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    /*
     * Somebody else edited this card while it was open.
     *
     * The prop is the truth — it arrived over the socket — so the local drafts
     * are replaced. Compared against the last value *seen* rather than against
     * the draft, so a reader halfway through retyping the title is not reset on
     * every unrelated board update.
     *
     * Adjusted during render rather than in an effect, which is what stops the
     * stale text being painted once before being corrected.
     */
    const [seen, setSeen] = useState({
        title: task.title,
        description: task.description,
    });

    if (seen.title !== task.title || seen.description !== task.description) {
        setSeen({ title: task.title, description: task.description });
        setTitle(task.title);
        setDescription(task.description ?? '');
    }

    const save = (attributes: Record<string, unknown>) => {
        void onUpdate(task.id, attributes);
    };

    const selectedLabelIds = (task.labels ?? []).map((label) => label.id);

    return (
        <div className="grid gap-6 md:grid-cols-[minmax(0,1fr)_16rem]">
            <div className="min-w-0">
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <TaskTypeIcon type={task.type} />
                    <span className="font-medium tabular-nums">
                        {task.reference}
                    </span>

                    {task.epic != null && (
                        <>
                            <span aria-hidden="true">·</span>
                            <span className="truncate">{task.epic.title}</span>
                        </>
                    )}
                </div>

                <DialogTitle asChild>
                    <textarea
                        value={title}
                        rows={1}
                        readOnly={!canContribute}
                        onChange={(event) => setTitle(event.target.value)}
                        onBlur={() => {
                            const trimmed = title.trim();

                            if (trimmed === '' || trimmed === task.title) {
                                // An emptied title is a slip, not an intent.
                                setTitle(task.title);

                                return;
                            }

                            save({ title: trimmed });
                        }}
                        className="mt-1 w-full resize-none bg-transparent text-lg font-semibold outline-none"
                    />
                </DialogTitle>

                <DialogDescription className="sr-only">
                    {task.reference} — {task.title}
                </DialogDescription>

                <label className="mt-4 block text-xs font-semibold text-muted-foreground uppercase">
                    Description
                </label>

                <textarea
                    value={description}
                    rows={6}
                    readOnly={!canContribute}
                    placeholder={
                        canContribute ? 'Add some detail…' : 'No description.'
                    }
                    onChange={(event) => setDescription(event.target.value)}
                    onBlur={() => {
                        if (description === (task.description ?? '')) {
                            return;
                        }

                        save({
                            description:
                                description === '' ? null : description,
                        });
                    }}
                    className="mt-1.5 w-full resize-y rounded-lg border border-border bg-background p-2.5 text-sm outline-none focus:border-brand"
                />

                {canDelete && (
                    <div className="mt-6 border-t border-border pt-4">
                        {confirmingDelete ? (
                            <div className="flex items-center gap-2">
                                <p className="text-sm text-muted-foreground">
                                    Delete {task.reference}? Anything linking to
                                    it will stop resolving.
                                </p>

                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={async () => {
                                        await onDelete(task.id);
                                        onClose();
                                    }}
                                >
                                    <Check className="size-4" />
                                    Delete
                                </Button>

                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setConfirmingDelete(false)}
                                >
                                    <X className="size-4" />
                                    Keep
                                </Button>
                            </div>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setConfirmingDelete(true)}
                                className="inline-flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-destructive"
                            >
                                <Trash2
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                                Delete this task
                            </button>
                        )}
                    </div>
                )}
            </div>

            <div className="space-y-3">
                <Field label="Status">
                    <Select
                        value={String(task.board_column_id)}
                        disabled={!canContribute}
                        onValueChange={(value) =>
                            onMoveToColumn(task, Number(value))
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {columns.map((column) => (
                                <SelectItem
                                    key={column.id}
                                    value={String(column.id)}
                                >
                                    {column.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field label="Assignee">
                    <Select
                        value={
                            task.assignee?.id
                                ? String(task.assignee.id)
                                : UNASSIGNED
                        }
                        disabled={!canContribute}
                        onValueChange={(value) =>
                            save({
                                assignee_id:
                                    value === UNASSIGNED ? null : Number(value),
                            })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={UNASSIGNED}>
                                Unassigned
                            </SelectItem>

                            {members.map((membership) => (
                                <SelectItem
                                    key={membership.id}
                                    value={String(membership.user.id)}
                                >
                                    <span className="flex items-center gap-2">
                                        <UserAvatar
                                            user={membership.user}
                                            size="xs"
                                        />
                                        {membership.user.name}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field label="Type">
                    <Select
                        value={task.type}
                        disabled={!canContribute}
                        onValueChange={(value) =>
                            save({ type: value as TaskType })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(TASK_TYPES).map(([value, meta]) => (
                                <SelectItem key={value} value={value}>
                                    <span className="flex items-center gap-2">
                                        <TaskTypeIcon
                                            type={value as TaskType}
                                        />
                                        {meta.label}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <Field label="Priority">
                    <Select
                        value={task.priority}
                        disabled={!canContribute}
                        onValueChange={(value) =>
                            save({ priority: value as TaskPriority })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(TASK_PRIORITIES).map(
                                ([value, meta]) => (
                                    <SelectItem key={value} value={value}>
                                        <span className="flex items-center gap-2">
                                            <TaskPriorityIcon
                                                priority={value as TaskPriority}
                                            />
                                            {meta.label}
                                        </span>
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </Field>

                {/* An epic cannot be inside an epic — the server drops the
                    parent on such a save, so offering the control here would be
                    offering something that silently does nothing. */}
                {task.type !== 'epic' && epics.length > 0 && (
                    <Field label="Epic">
                        <Select
                            value={task.epic?.id ? String(task.epic.id) : NONE}
                            disabled={!canContribute}
                            onValueChange={(value) =>
                                save({
                                    parent_task_id:
                                        value === NONE ? null : Number(value),
                                })
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>None</SelectItem>

                                {epics.map((epic) => (
                                    <SelectItem
                                        key={epic.id}
                                        value={String(epic.id)}
                                    >
                                        {epic.title}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                )}

                {/* Which version this goes out in — a different question from
                    which sprint it is worked in, and deliberately a separate
                    control. Hidden when the project has planned no releases,
                    so a team not shipping versions is not asked about them. */}
                {releases.length > 0 && (
                    <Field label="Release">
                        <Select
                            value={
                                task.release?.id
                                    ? String(task.release.id)
                                    : NONE
                            }
                            disabled={!canContribute}
                            onValueChange={(value) =>
                                save({
                                    release_id:
                                        value === NONE ? null : Number(value),
                                })
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>
                                    Not scheduled
                                </SelectItem>

                                {releases.map((release) => (
                                    <SelectItem
                                        key={release.id}
                                        value={String(release.id)}
                                    >
                                        {release.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                )}

                <Field label="Story points">
                    <input
                        type="number"
                        min={0}
                        max={999}
                        defaultValue={task.story_points ?? ''}
                        readOnly={!canContribute}
                        onBlur={(event) => {
                            const raw = event.target.value.trim();
                            const next = raw === '' ? null : Number(raw);

                            if (next !== task.story_points) {
                                save({ story_points: next });
                            }
                        }}
                        className="w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm outline-none focus:border-brand"
                    />
                </Field>

                <Field label="Due">
                    <input
                        type="date"
                        defaultValue={task.due_on ?? ''}
                        readOnly={!canContribute}
                        onChange={(event) =>
                            save({
                                due_on:
                                    event.target.value === ''
                                        ? null
                                        : event.target.value,
                            })
                        }
                        className="w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm outline-none focus:border-brand"
                    />
                </Field>

                {labels.length > 0 && (
                    <Field label="Labels">
                        <div className="flex flex-wrap gap-1.5">
                            {labels.map((label) => {
                                const selected = selectedLabelIds.includes(
                                    label.id,
                                );

                                return (
                                    <button
                                        key={label.id}
                                        type="button"
                                        disabled={!canContribute}
                                        onClick={() =>
                                            save({
                                                label_ids: selected
                                                    ? selectedLabelIds.filter(
                                                          (id) =>
                                                              id !== label.id,
                                                      )
                                                    : [
                                                          ...selectedLabelIds,
                                                          label.id,
                                                      ],
                                            })
                                        }
                                        className={cn(
                                            'rounded transition-opacity',
                                            selected
                                                ? 'ring-2 ring-brand ring-offset-1 ring-offset-background'
                                                : 'opacity-50 hover:opacity-100',
                                        )}
                                    >
                                        <LabelChip
                                            name={label.name}
                                            color={label.color}
                                        />
                                    </button>
                                );
                            })}
                        </div>
                    </Field>
                )}
            </div>
        </div>
    );
}

/**
 * Radix Select refuses an empty string as an item value — it reserves that for
 * "nothing chosen" — so the two absence cases need sentinels of their own.
 */
const UNASSIGNED = 'unassigned';
const NONE = 'none';

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <p className="mb-1 text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            {children}
        </div>
    );
}
