import { router } from '@inertiajs/react';
import { Check, MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';
import { TaskCard } from '@/components/snack/projects/task-card';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import {
    destroy as destroyColumn,
    update as updateColumn,
} from '@/routes/projects/columns';
import type { BoardColumn, CardFields, ColumnCategory, Task } from '@/types';

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
    canManage,
    workspaceSlug,
    projectSlug,
    draggingId,
    selected,
    onToggleSelected,
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
    /** A lead may reshape the board: rename, retype and remove its columns. */
    canManage: boolean;
    workspaceSlug: string;
    projectSlug: string;
    /** The card currently being dragged, so it can be dimmed in place. */
    draggingId: number | null;
    /** Ticked cards, when the board offers bulk actions. */
    selected?: ReadonlySet<number>;
    onToggleSelected?: (task: Task, range: boolean) => void;
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
                <ColumnName
                    column={column}
                    canManage={canManage}
                    workspaceSlug={workspaceSlug}
                    projectSlug={projectSlug}
                />

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

                {canManage && (
                    <ColumnMenu
                        column={column}
                        workspaceSlug={workspaceSlug}
                        projectSlug={projectSlug}
                    />
                )}
            </header>

            <div
                className="snack-scroll min-h-24 flex-1 overflow-y-auto overscroll-x-auto px-2 pb-2"
                /*
                 * `overscroll-x-auto` hands sideways scrolling back to the board.
                 * `snack-scroll` contains overscroll on both axes, and since an
                 * `overflow-y` scroller is a scroll container sideways too, a
                 * horizontal swipe over a column otherwise stopped dead inside it.
                 *
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
                            isSelected={selected?.has(task.id) ?? false}
                            showSelect={(selected?.size ?? 0) > 0}
                            onToggleSelected={onToggleSelected}
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
 * The column's name, renamed by clicking it.
 *
 * There is no settings screen for this any more: a column is renamed where it
 * is, because the thing being renamed is right there and the name only means
 * anything next to the ones either side of it. Enter or blur saves, Escape puts
 * the old name back, and an emptied name is a slip rather than an intent — the
 * same rules the card title follows.
 */
function ColumnName({
    column,
    canManage,
    workspaceSlug,
    projectSlug,
}: {
    column: BoardColumn;
    canManage: boolean;
    workspaceSlug: string;
    projectSlug: string;
}) {
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(column.name);

    const heading =
        'min-w-0 flex-1 truncate text-left text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase';

    if (!canManage) {
        return <h2 className={heading}>{column.name}</h2>;
    }

    if (!renaming) {
        return (
            <h2 className="min-w-0 flex-1">
                <button
                    type="button"
                    title="Rename column"
                    onClick={() => {
                        setName(column.name);
                        setRenaming(true);
                    }}
                    className={cn(
                        heading,
                        'w-full rounded transition-colors hover:text-foreground',
                    )}
                >
                    {column.name}
                </button>
            </h2>
        );
    }

    const save = () => {
        const trimmed = name.trim();

        setRenaming(false);

        if (trimmed === '' || trimmed === column.name) {
            return;
        }

        router.patch(
            updateColumn.url({
                workspace: workspaceSlug,
                project: projectSlug,
                column: column.id,
            }),
            { name: trimmed },
            { preserveScroll: true },
        );
    };

    return (
        <input
            autoFocus
            value={name}
            maxLength={40}
            aria-label={`Rename ${column.name}`}
            onChange={(event) => setName(event.target.value)}
            onBlur={save}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    save();
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    setName(column.name);
                    setRenaming(false);
                }
            }}
            className="min-w-0 flex-1 rounded border border-brand bg-background px-1 py-0.5 text-[0.6875rem] font-semibold tracking-wide uppercase outline-none"
        />
    );
}

/**
 * The rest of what a column can be: what it means, how much fits in it, and
 * removing it.
 *
 * Here rather than on the settings screen, for the reason the rename is: the
 * two questions this answers — "is this status started or done" and "is this
 * column too full" — are only askable while looking at the board.
 *
 * Removing does not take the cards with it: {@see App\Actions\Projects\DeleteBoardColumn}
 * moves them to the column beside it, which is why there is no confirmation to
 * click through.
 */
function ColumnMenu({
    column,
    workspaceSlug,
    projectSlug,
}: {
    column: BoardColumn;
    workspaceSlug: string;
    projectSlug: string;
}) {
    const args = {
        workspace: workspaceSlug,
        project: projectSlug,
        column: column.id,
    };

    const save = (attributes: {
        category?: ColumnCategory;
        wip_limit?: number | null;
    }) => {
        router.patch(updateColumn.url(args), attributes, {
            preserveScroll: true,
        });
    };

    /*
     * The limit is typed rather than picked, so it is held here and written
     * when the menu closes.
     *
     * Saving it on blur is what a field in a form would do, and it is wrong
     * here: closing the menu — by clicking away, or with Escape — unmounts the
     * input, and an unmounted field does not blur. The number would be lost by
     * the gesture that means "done".
     */
    const [open, setOpen] = useState(false);
    const [limit, setLimit] = useState(text(column.wip_limit));
    const [seen, setSeen] = useState(column.wip_limit);

    // The server's answer wins, including one arriving over the socket.
    if (seen !== column.wip_limit) {
        setSeen(column.wip_limit);
        setLimit(text(column.wip_limit));
    }

    /**
     * `typed` is passed by the Enter handler, which reads the field directly:
     * the keystroke that finished the number and the Enter that commits it can
     * arrive in the same tick, and state read in that tick is one keystroke
     * behind.
     */
    const commitLimit = (typed?: string) => {
        const raw = (typed ?? limit).trim();
        const next = raw === '' ? null : Number(raw);

        if (next !== column.wip_limit && (next === null || next > 0)) {
            save({ wip_limit: next });
        }
    };

    return (
        <DropdownMenu
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    commitLimit();
                }
            }}
        >
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="-mr-1.5 size-6 p-0 text-muted-foreground"
                >
                    <MoreHorizontal className="size-4" />
                    <span className="sr-only">Column options</span>
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    What it means
                </DropdownMenuLabel>

                {COLUMN_CATEGORIES.map(([category, label]) => (
                    <DropdownMenuItem
                        key={category}
                        onSelect={() => save({ category })}
                    >
                        {label}
                        {column.category === category && (
                            <Check className="ml-auto size-4" />
                        )}
                    </DropdownMenuItem>
                ))}

                <DropdownMenuSeparator />

                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Limit
                </DropdownMenuLabel>

                <div className="px-2 pb-1.5">
                    <input
                        type="number"
                        min={1}
                        max={999}
                        value={limit}
                        placeholder="No limit"
                        aria-label={`Work in progress limit for ${column.name}`}
                        onChange={(event) => setLimit(event.target.value)}
                        onKeyDown={(event) => {
                            // Typing into a menu otherwise drives its
                            // typeahead, which jumps focus to an item.
                            event.stopPropagation();

                            if (event.key === 'Enter') {
                                event.preventDefault();

                                // Committed here rather than left to the close:
                                // setting `open` directly is this component's
                                // own state change, and Radix only calls
                                // onOpenChange for the ones it makes itself.
                                commitLimit(event.currentTarget.value);
                                setOpen(false);
                            }
                        }}
                        className="w-full rounded-md border border-border bg-background px-2 py-1 text-sm outline-none focus:border-brand"
                    />
                </div>

                <DropdownMenuSeparator />

                {/* Styled text-destructive rather than variant="destructive",
                    which paints a near-black foreground in this theme. */}
                <DropdownMenuItem
                    className="text-destructive"
                    onSelect={() =>
                        router.delete(destroyColumn.url(args), {
                            preserveScroll: true,
                        })
                    }
                >
                    Delete column
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** A limit as the input holds it: the empty string is "no limit". */
function text(limit: number | null): string {
    return limit === null ? '' : String(limit);
}

/** The three meanings a column can carry, and what a board calls them. */
const COLUMN_CATEGORIES: Array<[ColumnCategory, string]> = [
    ['unstarted', 'To do'],
    ['started', 'In progress'],
    ['done', 'Done'],
];

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
