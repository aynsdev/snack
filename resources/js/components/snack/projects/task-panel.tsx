import { Check, Trash2, X } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import { DevelopmentSection } from '@/components/snack/projects/development-section';
import { TaskAgentControls } from '@/components/snack/projects/task-agent-controls';
import { TaskAttachments } from '@/components/snack/projects/task-attachments';
import { TaskComments } from '@/components/snack/projects/task-comments';
import {
    LabelChip,
    TASK_PRIORITIES,
    TASK_TYPES,
    TaskPriorityIcon,
    TaskTypeIcon,
    typeOptionsFor,
} from '@/components/snack/projects/task-glyphs';
import { TaskLinks } from '@/components/snack/projects/task-links';
import { TaskPicker } from '@/components/snack/projects/task-picker';
import { TaskSubtasks } from '@/components/snack/projects/task-subtasks';
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
import { useTaskDetail } from '@/hooks/use-task-detail';
import { cn } from '@/lib/utils';
import type {
    BoardColumn,
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
    agentsEnabled,
    releases,
    workspaceSlug,
    canContribute,
    canDelete,
    onClose,
    onUpdate,
    onDelete,
    onMoveToColumn,
    onOpenTask,
    onCreateTask,
}: {
    task: Task | null;
    columns: BoardColumn[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    agentsEnabled: boolean;
    releases: ReleaseOption[];
    workspaceSlug: string;
    canContribute: boolean;
    canDelete: boolean;
    onClose: () => void;
    onUpdate: (
        taskId: number,
        attributes: Record<string, unknown>,
    ) => Promise<unknown>;
    onDelete: (taskId: number) => Promise<void>;
    onMoveToColumn: (task: Task, columnId: number) => void;
    /** Open another card in this panel — a subtask, or the parent. */
    onOpenTask: (task: { id: number; reference: string }) => void;
    /** File a card on this project; used for subtasks. */
    onCreateTask: (attributes: Record<string, unknown>) => Promise<unknown>;
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
            <DialogContent className="max-h-[90dvh] w-[min(60rem,95vw)] max-w-none overflow-y-auto sm:max-w-none">
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
                        agentsEnabled={agentsEnabled}
                        releases={releases}
                        workspaceSlug={workspaceSlug}
                        canContribute={canContribute}
                        canDelete={canDelete}
                        onUpdate={onUpdate}
                        onDelete={onDelete}
                        onMoveToColumn={onMoveToColumn}
                        onOpenTask={onOpenTask}
                        onCreateTask={onCreateTask}
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
    agentsEnabled,
    releases,
    workspaceSlug,
    canContribute,
    canDelete,
    onUpdate,
    onDelete,
    onMoveToColumn,
    onOpenTask,
    onCreateTask,
    onClose,
}: {
    task: Task;
    columns: BoardColumn[];
    members: ProjectMembership[];
    labels: TaskLabel[];
    agentsEnabled: boolean;
    releases: ReleaseOption[];
    workspaceSlug: string;
    canContribute: boolean;
    canDelete: boolean;
    onUpdate: (
        taskId: number,
        attributes: Record<string, unknown>,
    ) => Promise<unknown>;
    onDelete: (taskId: number) => Promise<void>;
    onMoveToColumn: (task: Task, columnId: number) => void;
    onOpenTask: (task: { id: number; reference: string }) => void;
    onCreateTask: (attributes: Record<string, unknown>) => Promise<unknown>;
    onClose: () => void;
}) {
    const [title, setTitle] = useState(task.title);
    const titleField = useRef<HTMLTextAreaElement>(null);

    /*
     * The title wraps onto as many lines as it needs instead of scrolling
     * inside one: measured after every change, before paint, so a long title
     * never flashes a scrollbar.
     */
    useLayoutEffect(() => {
        const field = titleField.current;

        if (field === null) {
            return;
        }

        field.style.height = 'auto';
        field.style.height = `${field.scrollHeight}px`;
    }, [title]);
    const [description, setDescription] = useState(task.description ?? '');
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    /*
     * The discussion and the files are not on the board's copy of the card —
     * they are fetched when it is opened. See useTaskDetail for why.
     */
    const detail = useTaskDetail({ workspaceSlug, taskId: task.id });

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
                            <button
                                type="button"
                                onClick={() =>
                                    task.epic != null && onOpenTask(task.epic)
                                }
                                title={`Open ${task.epic.reference}`}
                                className="truncate hover:text-foreground hover:underline"
                            >
                                {task.epic.title}
                            </button>
                        </>
                    )}
                </div>

                <DialogTitle asChild>
                    <textarea
                        ref={titleField}
                        value={title}
                        rows={1}
                        readOnly={!canContribute}
                        onChange={(event) => setTitle(event.target.value)}
                        onKeyDown={(event) => {
                            // A title is one line of thought: Enter saves it
                            // rather than starting a second line.
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                event.currentTarget.blur();
                            }
                        }}
                        onBlur={() => {
                            const trimmed = title.trim();

                            if (trimmed === '' || trimmed === task.title) {
                                // An emptied title is a slip, not an intent.
                                setTitle(task.title);

                                return;
                            }

                            save({ title: trimmed });
                        }}
                        className="mt-1 w-full resize-none overflow-hidden bg-transparent text-lg leading-snug font-semibold break-words outline-none"
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

                {/* Nothing goes under a subtask. An epic lists its child
                    issues, as in Jira, and new ones are stories — a subtask
                    never sits straight under an epic. */}
                {task.type !== 'subtask' && (
                    <TaskSubtasks
                        heading={
                            task.type === 'epic' ? 'Child issues' : 'Subtasks'
                        }
                        subtasks={detail.detail?.subtasks ?? []}
                        canContribute={canContribute}
                        onOpen={onOpenTask}
                        onCreate={async (childTitle) => {
                            await onCreateTask({
                                title: childTitle,
                                type:
                                    task.type === 'epic' ? 'story' : 'subtask',
                                priority: 'medium',
                                parent_task_id: task.id,
                            });
                            await detail.refresh();
                        }}
                    />
                )}

                {detail.error !== null && (
                    <div className="mt-3 flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm">
                        <span className="flex-1">{detail.error}</span>
                        <button
                            type="button"
                            onClick={detail.dismissError}
                            className="text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <X aria-hidden="true" className="size-4" />
                            <span className="sr-only">Dismiss</span>
                        </button>
                    </div>
                )}

                <TaskLinks
                    workspaceSlug={workspaceSlug}
                    taskId={task.id}
                    links={detail.detail?.links ?? []}
                    relations={detail.detail?.relations ?? []}
                    canContribute={canContribute}
                    onAddLink={detail.addLink}
                    onRemoveLink={(id) => void detail.removeLink(id)}
                    onAddRelation={detail.addRelation}
                    onRemoveRelation={(id) => void detail.removeRelation(id)}
                />

                <TaskAttachments
                    attachments={detail.detail?.attachments ?? []}
                    uploading={detail.uploading}
                    canContribute={canContribute}
                    onAdd={(file) => void detail.addFile(file)}
                    onRemove={(attachment) =>
                        void detail.removeFile(attachment)
                    }
                />

                <TaskComments
                    comments={detail.detail?.comments ?? []}
                    canContribute={canContribute}
                    isLoading={detail.isLoading}
                    isSaving={detail.isSavingComment}
                    onAdd={(body) => void detail.addComment(body)}
                    onEdit={(id, body) => void detail.editComment(id, body)}
                    onRemove={(id) => void detail.removeComment(id)}
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

                {agentsEnabled && canContribute && (
                    <Field label="Agent">
                        <TaskAgentControls
                            task={task}
                            members={members}
                            onSave={(attributes) =>
                                onUpdate(task.id, attributes)
                            }
                        />
                    </Field>
                )}

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
                            {typeOptionsFor(task).map((value) => (
                                <SelectItem key={value} value={value}>
                                    <span className="flex items-center gap-2">
                                        <TaskTypeIcon type={value} />
                                        {TASK_TYPES[value].label}
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

                {/* Any card can sit under another — an epic, a story, a
                    task. An epic never has a parent, so it gets no control.
                    The picker leaves out this card and its own subtasks, so
                    nothing it offers can make a loop. */}
                {task.type !== 'epic' && (
                    <Field label="Parent">
                        {task.epic != null ? (
                            <div className="flex items-center gap-2 rounded-lg border border-border px-2.5 py-1.5 text-sm">
                                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                    {task.epic.reference}
                                </span>
                                <span className="min-w-0 flex-1 truncate">
                                    {task.epic.title}
                                </span>
                                {canContribute && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            save({ parent_task_id: null })
                                        }
                                        className="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
                                    >
                                        <X
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        <span className="sr-only">
                                            Remove parent
                                        </span>
                                    </button>
                                )}
                            </div>
                        ) : canContribute ? (
                            <TaskPicker
                                workspaceSlug={workspaceSlug}
                                taskId={task.id}
                                purpose="parent"
                                placeholder="Choose a parent…"
                                onPick={(option) =>
                                    save({ parent_task_id: option.id })
                                }
                            />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                None
                            </p>
                        )}
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

                {/* Where the work happens, beside the fields describing it —
                    the same place Jira keeps it, and for the same reason: it is
                    consulted at the start of the work rather than read with the
                    description. */}
                <DevelopmentSection task={task} />

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
