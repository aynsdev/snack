import { useQuery } from '@tanstack/react-query';
import { Loader2, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { TaskTypeIcon } from '@/components/snack/projects/task-glyphs';
import { Input } from '@/components/ui/input';
import { api } from '@/lib/api';
import { picker } from '@/routes/tasks';
import type { TaskPickerOption } from '@/types';

/**
 * A search box for choosing another card — this card's parent, or a card to
 * link it to.
 *
 * Searches the server rather than a list held by the board, because a project
 * is easily a thousand cards. For a parent, the server leaves out this card
 * and everything beneath it, so nothing offered here can make a loop.
 */
export function TaskPicker({
    workspaceSlug,
    taskId,
    purpose,
    placeholder,
    autoFocus = false,
    onPick,
}: {
    workspaceSlug: string;
    taskId: number;
    purpose: 'parent' | 'relation';
    placeholder: string;
    autoFocus?: boolean;
    onPick: (option: TaskPickerOption) => void;
}) {
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(autoFocus);

    // Waits for a pause in typing, so each keystroke is not a request.
    useEffect(() => {
        const timer = window.setTimeout(() => setQuery(search.trim()), 250);

        return () => window.clearTimeout(timer);
    }, [search]);

    const results = useQuery({
        queryKey: ['task-picker', taskId, purpose, query],
        enabled: open,
        staleTime: 30_000,
        queryFn: async ({ signal }) => {
            const { tasks } = await api.get<{ tasks: TaskPickerOption[] }>(
                picker.url(
                    { workspace: workspaceSlug, task: taskId },
                    { query: { q: query, purpose } },
                ),
                signal,
            );

            return tasks;
        },
    });

    return (
        <div
            className="relative"
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) {
                    setOpen(false);
                }
            }}
        >
            <Search
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                value={search}
                autoFocus={autoFocus}
                placeholder={placeholder}
                aria-label={placeholder}
                onFocus={() => setOpen(true)}
                onChange={(event) => {
                    setSearch(event.target.value);
                    setOpen(true);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        setOpen(false);
                    }
                }}
                className="h-8 pl-8 text-sm"
            />

            {open && (
                <div className="absolute inset-x-0 top-full z-50 mt-1 max-h-64 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-md">
                    {results.isLoading ? (
                        <p className="flex items-center gap-2 px-2 py-1.5 text-sm text-muted-foreground">
                            <Loader2
                                aria-hidden="true"
                                className="size-3.5 animate-spin"
                            />
                            Searching…
                        </p>
                    ) : (results.data ?? []).length === 0 ? (
                        <p className="px-2 py-1.5 text-sm text-muted-foreground">
                            No matching tasks.
                        </p>
                    ) : (
                        (results.data ?? []).map((option) => (
                            <button
                                key={option.id}
                                type="button"
                                onClick={() => {
                                    onPick(option);
                                    setSearch('');
                                    setOpen(false);
                                }}
                                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                            >
                                <TaskTypeIcon type={option.type} />
                                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                    {option.reference}
                                </span>
                                <span className="truncate">{option.title}</span>
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
