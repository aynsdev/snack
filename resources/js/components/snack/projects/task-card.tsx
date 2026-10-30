import { CalendarDays, ListTree, Zap } from 'lucide-react';
import { useRef } from 'react';
import {
    AgentBadge,
    LabelChip,
    TaskPriorityIcon,
    TaskTypeIcon,
} from '@/components/snack/projects/task-glyphs';
import { UserAvatar } from '@/components/snack/user-avatar';
import { agentStateOf, withoutReservedLabels } from '@/lib/agent-labels';
import { CLICK_LIKE_DRAG_MS } from '@/lib/board-drag';
import { cn } from '@/lib/utils';
import type { CardFields, Task } from '@/types';

/**
 * One card on the board.
 *
 * # What "customizable" means here
 *
 * Everything below the title is drawn only if the project asked for it. A team
 * that does not estimate should not look at an empty points slot on every card,
 * and a two-person project has no use for an avatar that is always the same
 * face. The toggles live on the project (see App\Models\Project::CARD_FIELD_DEFAULTS)
 * and arrive resolved, so this never has to treat a missing key as a third
 * state.
 *
 * The title is not among them. A card with no title is not a card.
 *
 * # Dragging
 *
 * Native HTML5 drag and drop rather than a library: a board is one draggable
 * element and one drop target, the browser already implements both, and the
 * accessible fallback — moving a card from its own detail panel — has to exist
 * either way for anyone not using a mouse.
 */
export function TaskCard({
    task,
    fields,
    isDragging = false,
    draggable = false,
    onOpen,
    onDragStart,
    onDragEnd,
}: {
    task: Task;
    fields: CardFields;
    isDragging?: boolean;
    draggable?: boolean;
    onOpen: (task: Task) => void;
    onDragStart?: (task: Task) => void;
    onDragEnd?: () => void;
}) {
    const labels = withoutReservedLabels(task.labels ?? []);
    const agentState = agentStateOf(task.labels);
    const dragStartedAt = useRef<number | null>(null);

    return (
        <article
            draggable={draggable}
            onClick={() => onOpen(task)}
            onDragStart={(event) => {
                dragStartedAt.current = event.timeStamp;
                // The id travels in the payload as well as in React state: a
                // drop handler reading only from state would be reading whatever
                // was last set, and drags can be started in another window.
                event.dataTransfer.setData('text/plain', String(task.id));
                event.dataTransfer.effectAllowed = 'move';
                onDragStart?.(task);
            }}
            onDragEnd={(event) => {
                const startedAt = dragStartedAt.current;
                dragStartedAt.current = null;
                onDragEnd?.();

                /*
                 * Browsers start a native drag on a few pixels of pointer
                 * movement, which swallows the click. A brief drag that landed
                 * nowhere was meant as a click, so it opens the card.
                 */
                if (
                    event.dataTransfer.dropEffect === 'none' &&
                    startedAt !== null &&
                    event.timeStamp - startedAt < CLICK_LIKE_DRAG_MS
                ) {
                    onOpen(task);
                }
            }}
            className={cn(
                'group rounded-lg border border-border bg-card p-2.5 text-left shadow-sm transition-all',
                'hover:border-brand/40 hover:shadow',
                'cursor-pointer',
                draggable && 'active:cursor-grabbing',
                isDragging && 'opacity-40',
            )}
        >
            {/*
             * A button around the text rather than around the whole article: the
             * card is a drag handle, and a button that is also draggable
             * swallows the keyboard activation on some browsers. It is the
             * keyboard target only; its click bubbles to the article, which
             * opens the card from anywhere on it.
             */}
            <button type="button" className="w-full text-left">
                <p
                    className={cn(
                        'text-sm leading-snug',
                        task.is_complete &&
                            'text-muted-foreground line-through',
                    )}
                >
                    {task.title}
                </p>
            </button>

            {fields.epic && task.epic != null && (
                <p className="mt-1.5 inline-flex max-w-full items-center gap-1 truncate rounded bg-violet-500/10 px-1.5 py-0.5 text-[0.625rem] font-medium text-violet-600 dark:text-violet-300">
                    <Zap aria-hidden="true" className="size-2.5 shrink-0" />
                    <span className="truncate">{task.epic.title}</span>
                </p>
            )}

            {fields.labels && (labels.length > 0 || agentState !== null) && (
                <div className="mt-1.5 flex flex-wrap gap-1">
                    {agentState !== null && <AgentBadge state={agentState} />}
                    {labels.map((label) => (
                        <LabelChip
                            key={label.id}
                            name={label.name}
                            color={label.color}
                        />
                    ))}
                </div>
            )}

            <div className="mt-2 flex items-center gap-1.5">
                {fields.type && <TaskTypeIcon type={task.type} />}
                {fields.priority && (
                    <TaskPriorityIcon priority={task.priority} />
                )}

                {fields.key && (
                    <span
                        className={cn(
                            'text-[0.6875rem] font-medium text-muted-foreground tabular-nums',
                            task.is_complete && 'line-through',
                        )}
                    >
                        {task.reference}
                    </span>
                )}

                {fields.story_points && task.story_points !== null && (
                    <span
                        title={`${task.story_points} points`}
                        className="rounded-full bg-muted px-1.5 py-0.5 text-[0.625rem] leading-none font-semibold text-muted-foreground tabular-nums"
                    >
                        {task.story_points}
                    </span>
                )}

                {task.subtask_progress != null && (
                    <span
                        title={`${task.subtask_progress.done} of ${task.subtask_progress.total} subtasks done`}
                        className={cn(
                            'inline-flex items-center gap-0.5 text-[0.625rem] font-medium tabular-nums',
                            task.subtask_progress.done ===
                                task.subtask_progress.total
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-muted-foreground',
                        )}
                    >
                        <ListTree aria-hidden="true" className="size-3" />
                        {task.subtask_progress.done}/
                        {task.subtask_progress.total}
                    </span>
                )}

                {fields.due_on && task.due_on !== null && (
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 text-[0.625rem] font-medium',
                            isOverdue(task) && !task.is_complete
                                ? 'text-rose-500'
                                : 'text-muted-foreground',
                        )}
                    >
                        <CalendarDays aria-hidden="true" className="size-3" />
                        {formatDueDate(task.due_on)}
                    </span>
                )}

                {fields.assignee && (
                    <span className="ml-auto">
                        {task.assignee == null ? (
                            // A dashed circle rather than nothing: "nobody has
                            // this" is information, and it is what somebody
                            // scanning for work to pick up is looking for.
                            <span
                                title="Unassigned"
                                className="block size-6 rounded-full border border-dashed border-muted-foreground/40"
                            />
                        ) : (
                            <UserAvatar user={task.assignee} size="sm" />
                        )}
                    </span>
                )}
            </div>
        </article>
    );
}

/**
 * "5 Aug" — the year only when it is not this one, which is how a date is read
 * out loud and keeps the chip narrow enough to sit on a card.
 */
export function formatDueDate(date: string): string {
    const parsed = new Date(date);
    const thisYear = parsed.getFullYear() === new Date().getFullYear();

    return parsed.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        ...(thisYear ? {} : { year: 'numeric' }),
    });
}

function isOverdue(task: Task): boolean {
    if (task.due_on === null) {
        return false;
    }

    // Compared by date rather than by instant: something due today is not
    // overdue at nine in the morning.
    const due = new Date(task.due_on);
    const today = new Date();

    due.setHours(23, 59, 59, 999);

    return due.getTime() < today.getTime();
}
