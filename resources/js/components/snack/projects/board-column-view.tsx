import { Plus } from 'lucide-react';
import { useState } from 'react';
import { TaskCard } from '@/components/snack/projects/task-card';
import { cn } from '@/lib/utils';
import type { BoardColumn, CardFields, Task } from '@/types';

/**
 * One column of the board: its cards, its count, and the seam a dragged card
 * can be dropped into.
 *
 * # How a drop knows where it landed
 *
 * Each card has a drop zone above it and the column has one at the end, and the
 * index of the zone the pointer is over *is* the destination slot. Working it
 * out from the pointer's Y position against every card's bounding box is the
 * other way to do this, and it disagrees with what the reader sees the moment a
 * card is taller than its neighbours.
 *
 * The zone is a one-pixel line that grows to a visible bar while a card is over
 * it, so an empty column and the gap between two cards are both targets big
 * enough to hit without the layout shifting as you cross them.
 */
export function BoardColumnView({
    column,
    tasks,
    fields,
    canContribute,
    draggingId,
    onOpenTask,
    onDragStart,
    onDragEnd,
    onDrop,
    onCreate,
}: {
    column: BoardColumn;
    tasks: Task[];
    fields: CardFields;
    canContribute: boolean;
    /** The card currently being dragged, so it can be dimmed in place. */
    draggingId: number | null;
    onOpenTask: (task: Task) => void;
    onDragStart: (task: Task) => void;
    onDragEnd: () => void;
    onDrop: (columnId: number, index: number) => void;
    onCreate: (columnId: number, title: string) => Promise<void>;
}) {
    const [over, setOver] = useState<number | null>(null);
    const [composing, setComposing] = useState(false);

    const isOverLimit =
        column.wip_limit !== null && tasks.length > column.wip_limit;

    const dropZone = (index: number) => (
        <div
            onDragOver={(event) => {
                if (draggingId === null) {
                    return;
                }

                // Without this the browser refuses the drop outright — the
                // default for dragover is "not a drop target".
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                setOver(index);
            }}
            onDragLeave={() =>
                setOver((current) => (current === index ? null : current))
            }
            onDrop={(event) => {
                event.preventDefault();
                setOver(null);
                onDrop(column.id, index);
            }}
            className={cn(
                'rounded-full transition-all',
                over === index && draggingId !== null
                    ? 'my-1 h-1.5 bg-brand'
                    : 'h-1',
            )}
        />
    );

    return (
        <section
            aria-label={column.name}
            className="flex w-72 shrink-0 flex-col rounded-xl bg-muted/40"
        >
            <header className="flex items-center gap-2 px-3 pt-3 pb-1">
                <h2 className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
                    {column.name}
                </h2>

                <span
                    className={cn(
                        'rounded-full px-1.5 py-0.5 text-[0.625rem] leading-none font-semibold tabular-nums',
                        // Over the limit is a warning, not a refusal — see the
                        // board_columns migration for why the drop still lands.
                        isOverLimit
                            ? 'bg-rose-500/15 text-rose-600 dark:text-rose-300'
                            : 'bg-muted text-muted-foreground',
                    )}
                    title={
                        column.wip_limit === null
                            ? undefined
                            : `Limit ${column.wip_limit}`
                    }
                >
                    {column.wip_limit === null
                        ? tasks.length
                        : `${tasks.length}/${column.wip_limit}`}
                </span>
            </header>

            <div
                className="snack-scroll min-h-24 flex-1 overflow-y-auto px-2 pb-2"
                /*
                 * The column itself is a drop target as well as the seams, so a
                 * card released over empty space below the last one still lands
                 * — at the end, which is where it looks like it is going.
                 */
                onDragOver={(event) => {
                    if (draggingId !== null) {
                        event.preventDefault();
                    }
                }}
                onDrop={(event) => {
                    event.preventDefault();
                    setOver(null);
                    onDrop(column.id, tasks.length);
                }}
            >
                {tasks.map((task, index) => (
                    <div key={task.id}>
                        {dropZone(index)}

                        <TaskCard
                            task={task}
                            fields={fields}
                            draggable={canContribute}
                            isDragging={draggingId === task.id}
                            onOpen={onOpenTask}
                            onDragStart={onDragStart}
                            onDragEnd={onDragEnd}
                        />
                    </div>
                ))}

                {dropZone(tasks.length)}

                {tasks.length === 0 && draggingId === null && (
                    <p className="px-2 py-6 text-center text-xs text-muted-foreground">
                        Nothing here.
                    </p>
                )}
            </div>

            {canContribute && (
                <div className="px-2 pb-2">
                    {composing ? (
                        <InlineTaskComposer
                            onCancel={() => setComposing(false)}
                            onSubmit={async (title) => {
                                await onCreate(column.id, title);
                            }}
                        />
                    ) : (
                        <button
                            type="button"
                            onClick={() => setComposing(true)}
                            className="flex w-full items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        >
                            <Plus aria-hidden="true" className="size-4" />
                            Create
                        </button>
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * Filing a card without leaving the board.
 *
 * A title and nothing else, deliberately: the point of this control is that
 * something remembered mid-conversation gets written down before it is
 * forgotten. Everything else about the card is set later, in the panel, by
 * whoever picks it up.
 *
 * Stays open after a submit so a list of five things is five keystrokes and four
 * Enters, and Escape is what closes it.
 */
function InlineTaskComposer({
    onSubmit,
    onCancel,
}: {
    onSubmit: (title: string) => Promise<void>;
    onCancel: () => void;
}) {
    const [title, setTitle] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = async () => {
        const trimmed = title.trim();

        if (trimmed === '' || saving) {
            return;
        }

        setSaving(true);

        try {
            await onSubmit(trimmed);
            setTitle('');
        } finally {
            setSaving(false);
        }
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                void submit();
            }}
            className="rounded-lg border border-border bg-card p-2 shadow-sm"
        >
            <textarea
                autoFocus
                rows={2}
                value={title}
                disabled={saving}
                onChange={(event) => setTitle(event.target.value)}
                onKeyDown={(event) => {
                    // Enter files it; Shift+Enter is a newline, matching the
                    // composer everywhere else in Snack.
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        void submit();
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        onCancel();
                    }
                }}
                onBlur={() => {
                    if (title.trim() === '') {
                        onCancel();
                    }
                }}
                placeholder="What needs doing?"
                aria-label="Task title"
                className="w-full resize-none bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            />
        </form>
    );
}
