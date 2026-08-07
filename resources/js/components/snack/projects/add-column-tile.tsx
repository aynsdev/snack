import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store as storeColumn } from '@/routes/projects/columns';
import type { ColumnCategory } from '@/types';

/**
 * Adding a column without leaving the board.
 *
 * # Where it sits, and where the column goes
 *
 * The button is a small square at the end of the strip — one control at the
 * edge of the board rather than a full-height column pretending to be one. What
 * it adds, though, lands *before* Done: the status somebody adds mid-flow is
 * another step before finished — "To test", "Blocked", "To release" — and a
 * column arriving to the right of Done is a board that reads backwards until
 * somebody reorders it.
 *
 * The neighbours travel as ids, never as positions, for the reason a dropped
 * card sends ids: which numbers the columns hold is the server's business.
 *
 * # Why it still asks for a category
 *
 * Nothing in this application may infer status from a column's name — see
 * ColumnCategory. "To test" is a started column on one board and a done column
 * on another, and only the team knows which. It is one select, defaulted to In
 * progress, because that is what a column added here nearly always is.
 */
export function AddColumnTile({
    workspaceSlug,
    projectSlug,
    beforeColumnId,
    afterColumnId,
}: {
    workspaceSlug: string;
    projectSlug: string;
    /** The column to its left, or null at the start of the board. */
    beforeColumnId: number | null;
    /** The column to its right — the Done column, usually. */
    afterColumnId: number | null;
}) {
    const [composing, setComposing] = useState(false);
    const [name, setName] = useState('');
    const [category, setCategory] = useState<ColumnCategory>('started');
    const [saving, setSaving] = useState(false);

    const close = () => {
        setComposing(false);
        setName('');
        setCategory('started');
    };

    const submit = () => {
        if (name.trim() === '' || saving) {
            return;
        }

        setSaving(true);

        router.post(
            storeColumn.url({
                workspace: workspaceSlug,
                project: projectSlug,
            }),
            {
                name: name.trim(),
                category,
                before_column_id: beforeColumnId,
                after_column_id: afterColumnId,
            },
            {
                preserveScroll: true,
                onSuccess: close,
                onFinish: () => setSaving(false),
            },
        );
    };

    if (!composing) {
        return (
            <button
                type="button"
                title="Add column"
                onClick={() => setComposing(true)}
                className="flex size-11 shrink-0 items-center justify-center self-start rounded-xl border border-border text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            >
                <Plus aria-hidden="true" className="size-5" />
                <span className="sr-only">Add column</span>
            </button>
        );
    }

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close();
                }
            }}
            className="flex w-72 shrink-0 flex-col gap-2 self-start rounded-xl bg-muted/40 p-3"
        >
            <Input
                autoFocus
                value={name}
                disabled={saving}
                maxLength={40}
                placeholder="To test"
                aria-label="Column name"
                onChange={(event) => setName(event.target.value)}
            />

            <Select
                value={category}
                onValueChange={(value) => setCategory(value as ColumnCategory)}
            >
                <SelectTrigger className="w-full" aria-label="What it means">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="unstarted">To do</SelectItem>
                    <SelectItem value="started">In progress</SelectItem>
                    <SelectItem value="done">Done</SelectItem>
                </SelectContent>
            </Select>

            <p className="text-xs text-muted-foreground">
                What the column means decides where new work lands and what
                counts as finished when a sprint closes.
            </p>

            <div className="flex items-center gap-2">
                <Button type="submit" size="sm" disabled={saving}>
                    Add column
                </Button>

                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={close}
                    className="text-muted-foreground"
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
