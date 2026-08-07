import { Search, SlidersHorizontal, X } from 'lucide-react';
import {
    TASK_TYPES,
    TaskTypeIcon,
} from '@/components/snack/projects/task-glyphs';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {  hasBoardFilters } from '@/hooks/use-board';
import type {BoardFilters} from '@/hooks/use-board';
import { cn } from '@/lib/utils';
import type { ProjectMembership, ReleaseOption, TaskType } from '@/types';

/**
 * The bar above the columns.
 *
 * Everything here narrows the cards the client already holds — see
 * `filterTasks` — so a board reacts as it is typed into rather than reloading.
 * That is also why there is no Apply button: with no request to send, waiting
 * for one would be waiting for nothing.
 *
 * The people are avatars rather than a dropdown because "whose work is this" is
 * the question asked most often at a standup, and a face is faster to hit than a
 * menu to open. Several can be on at once — two people pairing want both.
 */
export function BoardFiltersBar({
    filters,
    members,
    releases,
    onChange,
}: {
    filters: BoardFilters;
    members: ProjectMembership[];
    releases: ReleaseOption[];
    onChange: (filters: BoardFilters) => void;
}) {
    const set = (change: Partial<BoardFilters>) =>
        onChange({ ...filters, ...change });

    const toggleAssignee = (who: number | 'unassigned') =>
        set({
            assignees: filters.assignees.includes(who)
                ? filters.assignees.filter((one) => one !== who)
                : [...filters.assignees, who],
        });

    return (
        <div className="flex flex-wrap items-center gap-2 px-4 pt-3">
            <div className="relative">
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    value={filters.search}
                    placeholder="Search board"
                    aria-label="Search board"
                    onChange={(event) => set({ search: event.target.value })}
                    className="h-9 w-52 pl-8"
                />
            </div>

            {/* Overlapping, the way a board's people are drawn everywhere else;
                a selected face steps forward with a ring rather than changing
                size, so the row does not reflow as people are picked. */}
            <div className="flex items-center pl-1.5">
                {members.slice(0, 8).map((membership) => {
                    const on = filters.assignees.includes(membership.user.id);

                    return (
                        <button
                            key={membership.id}
                            type="button"
                            aria-pressed={on}
                            title={membership.user.name}
                            onClick={() => toggleAssignee(membership.user.id)}
                            className={cn(
                                '-ml-1.5 rounded-full ring-2 transition-transform',
                                on
                                    ? 'z-10 ring-brand'
                                    : 'ring-background hover:-translate-y-0.5',
                            )}
                        >
                            <UserAvatar user={membership.user} size="sm" />
                            <span className="sr-only">
                                Show only {membership.user.name}’s cards
                            </span>
                        </button>
                    );
                })}

                <button
                    type="button"
                    aria-pressed={filters.assignees.includes('unassigned')}
                    title="Unassigned"
                    onClick={() => toggleAssignee('unassigned')}
                    className={cn(
                        '-ml-1.5 flex size-7 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground ring-2 transition-transform',
                        filters.assignees.includes('unassigned')
                            ? 'z-10 ring-brand'
                            : 'ring-background hover:-translate-y-0.5',
                    )}
                >
                    ?<span className="sr-only">Show unassigned cards</span>
                </button>
            </div>

            {/* Hidden when the project ships no versions, rather than offering a
                filter whose only option is "none". */}
            {releases.length > 0 && (
                <Select
                    value={String(filters.release)}
                    onValueChange={(value) =>
                        set({
                            release:
                                value === 'all' || value === 'none'
                                    ? value
                                    : Number(value),
                        })
                    }
                >
                    <SelectTrigger className="h-9 w-36" aria-label="Version">
                        <SelectValue placeholder="Version" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Any version</SelectItem>
                        <SelectItem value="none">No version</SelectItem>
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
            )}

            <Select
                value={filters.type}
                onValueChange={(value) =>
                    set({ type: value as TaskType | 'all' })
                }
            >
                <SelectTrigger className="h-9 w-32" aria-label="Type">
                    <SelectValue placeholder="Type" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Any type</SelectItem>
                    {Object.entries(TASK_TYPES).map(([value, meta]) => (
                        <SelectItem key={value} value={value}>
                            <span className="flex items-center gap-2">
                                <TaskTypeIcon type={value as TaskType} />
                                {meta.label}
                            </span>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button type="button" variant="outline" size="sm" className="h-9">
                        <SlidersHorizontal aria-hidden="true" />
                        Quick filters
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    <DropdownMenuCheckboxItem
                        checked={filters.onlyMine}
                        onCheckedChange={(checked) =>
                            set({ onlyMine: checked === true })
                        }
                    >
                        Only my cards
                    </DropdownMenuCheckboxItem>
                    <DropdownMenuCheckboxItem
                        checked={filters.recentlyUpdated}
                        onCheckedChange={(checked) =>
                            set({ recentlyUpdated: checked === true })
                        }
                    >
                        Updated in the last day
                    </DropdownMenuCheckboxItem>
                </DropdownMenuContent>
            </DropdownMenu>

            {hasBoardFilters(filters) && (
                <button
                    type="button"
                    onClick={() =>
                        onChange({
                            search: '',
                            assignees: [],
                            release: 'all',
                            type: 'all',
                            onlyMine: false,
                            recentlyUpdated: false,
                        })
                    }
                    className="flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                >
                    <X aria-hidden="true" className="size-3.5" />
                    Clear filters
                </button>
            )}
        </div>
    );
}
