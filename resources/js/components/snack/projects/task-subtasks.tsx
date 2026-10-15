import { ListTree, Loader2, Plus } from 'lucide-react';
import { useState } from 'react';
import { TaskTypeIcon } from '@/components/snack/projects/task-glyphs';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { TaskSubtask } from '@/types';

/**
 * The work broken out of a card, the way Jira lists child issues: how much is
 * done, each subtask with its status and owner, and a box to add the next one
 * without leaving the parent.
 *
 * A new subtask lands in the parent's sprint and release (the server fills
 * those in), so breaking work down never drops the pieces into the backlog.
 */
export function TaskSubtasks({
    heading,
    subtasks,
    canContribute,
    onOpen,
    onCreate,
}: {
    /** "Subtasks", or "Child issues" on an epic. */
    heading: string;
    subtasks: TaskSubtask[];
    canContribute: boolean;
    onOpen: (subtask: TaskSubtask) => void;
    onCreate: (title: string) => Promise<void>;
}) {
    const [adding, setAdding] = useState(false);
    const [title, setTitle] = useState('');
    const [saving, setSaving] = useState(false);

    const done = subtasks.filter((subtask) => subtask.is_complete).length;
    const percent =
        subtasks.length === 0 ? 0 : Math.round((done / subtasks.length) * 100);

    const submit = async () => {
        const trimmed = title.trim();

        if (trimmed === '' || saving) {
            return;
        }

        setSaving(true);

        try {
            await onCreate(trimmed);
            // Cleared but left open, so a list of subtasks can be typed in
            // one go — the way it is usually done.
            setTitle('');
        } finally {
            setSaving(false);
        }
    };

    if (subtasks.length === 0 && !canContribute) {
        return null;
    }

    return (
        <section className="mt-6 border-t border-border pt-4">
            <div className="flex items-center justify-between gap-2">
                <h3 className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    <ListTree aria-hidden="true" className="size-3.5" />
                    {heading}
                    {subtasks.length > 0 && (
                        <span className="tabular-nums">
                            ({done}/{subtasks.length})
                        </span>
                    )}
                </h3>

                {canContribute && !adding && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setAdding(true)}
                    >
                        <Plus aria-hidden="true" />
                        Create
                        {heading === 'Subtasks' ? ' subtask' : ' child issue'}
                    </Button>
                )}
            </div>

            {subtasks.length > 0 && (
                <div
                    role="progressbar"
                    aria-label={`${heading} done`}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={percent}
                    className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        className="h-full rounded-full bg-emerald-500 transition-[width] duration-300"
                        style={{ width: `${percent}%` }}
                    />
                </div>
            )}

            <ul className="mt-2 space-y-1">
                {subtasks.map((subtask) => (
                    <li key={subtask.id}>
                        <button
                            type="button"
                            onClick={() => onOpen(subtask)}
                            className="flex w-full items-center gap-2.5 rounded-lg border border-border bg-card px-3 py-2 text-left text-sm transition-colors hover:bg-accent"
                        >
                            <TaskTypeIcon type={subtask.type} />
                            <span
                                className={cn(
                                    'shrink-0 text-xs font-medium text-muted-foreground tabular-nums',
                                    subtask.is_complete && 'line-through',
                                )}
                            >
                                {subtask.reference}
                            </span>
                            <span
                                className={cn(
                                    'min-w-0 flex-1 truncate',
                                    subtask.is_complete &&
                                        'text-muted-foreground',
                                )}
                            >
                                {subtask.title}
                            </span>
                            <span
                                className={cn(
                                    'shrink-0 rounded px-1.5 py-0.5 text-[0.6875rem]',
                                    subtask.is_complete
                                        ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {subtask.status}
                            </span>
                            {subtask.assignee === null ? (
                                <span
                                    title="Unassigned"
                                    className="block size-5 shrink-0 rounded-full border border-dashed border-muted-foreground/40"
                                />
                            ) : (
                                <UserAvatar user={subtask.assignee} size="xs" />
                            )}
                        </button>
                    </li>
                ))}
            </ul>

            {adding && (
                <form
                    className="mt-2 flex items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <Input
                        autoFocus
                        value={title}
                        maxLength={200}
                        placeholder="What needs to be done?"
                        aria-label="Title"
                        onChange={(event) => setTitle(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                event.stopPropagation();
                                setAdding(false);
                                setTitle('');
                            }
                        }}
                        className="h-8 text-sm"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        disabled={saving || title.trim() === ''}
                    >
                        {saving && (
                            <Loader2
                                aria-hidden="true"
                                className="animate-spin"
                            />
                        )}
                        Create
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            setAdding(false);
                            setTitle('');
                        }}
                    >
                        Cancel
                    </Button>
                </form>
            )}

            {subtasks.length === 0 && !adding && (
                <p className="mt-2 text-sm text-muted-foreground">
                    Nothing here yet.
                </p>
            )}
        </section>
    );
}
