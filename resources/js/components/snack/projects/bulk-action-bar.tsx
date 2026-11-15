import { Bot, CalendarRange, ChevronDown, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentUser } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import type { ProjectMembership, Sprint } from '@/types';

export type BulkChange = {
    sprint_id?: number;
    in_backlog?: boolean;
    agent?: 'assign';
    assignee_id?: number;
};

/**
 * What can be done to several ticked cards at once: commit them to a sprint
 * (or send them back to the backlog), or hand them to an agent.
 *
 * The same two choices the card's own menus offer — the sprint list of
 * {@see TaskRowMenu} and the people of {@see TaskAgentControls} — so a bulk
 * action is never something a single card could not have had done to it.
 * Handing to yourself starts your agent; handing to anybody else only asks,
 * and they press Start, exactly as on one card.
 *
 * Shown only while something is ticked, or while there is a note to read
 * about the last action — cards that already had an agent were left alone, and
 * the reader should hear which. A refusal keeps the selection, so the reader
 * can pick another sprint or person without ticking everything again.
 */
export function BulkActionBar({
    count,
    sprints,
    members,
    agentsEnabled,
    onApply,
    onClear,
}: {
    count: number;
    /** Sprints a card may be moved into; completed ones are filtered out here. */
    sprints: Sprint[];
    members: ProjectMembership[];
    agentsEnabled: boolean;
    /** Resolves with the references left alone, or why nothing changed. */
    onApply: (
        change: BulkChange,
    ) => Promise<{ skipped: string[] } | { error: string }>;
    onClear: () => void;
}) {
    const me = useCurrentUser();
    const [pending, setPending] = useState(false);
    const [notice, setNotice] = useState<{
        text: string;
        tone: 'info' | 'error';
    } | null>(null);

    // A completed sprint is history; work is never moved into one.
    const destinations = sprints.filter(
        (sprint) => sprint.state !== 'completed',
    );

    const others = useMemo(
        () => members.filter((membership) => membership.user.id !== me?.id),
        [members, me?.id],
    );

    if (count === 0 && notice === null) {
        return null;
    }

    const apply = async (change: BulkChange) => {
        setPending(true);
        setNotice(null);

        try {
            const result = await onApply(change);

            if ('error' in result) {
                setNotice({ text: result.error, tone: 'error' });

                return;
            }

            if (result.skipped.length > 0) {
                setNotice({
                    text: skippedNotice(result.skipped),
                    tone: 'info',
                });
            }

            onClear();
        } finally {
            setPending(false);
        }
    };

    return (
        <div
            role="region"
            aria-label="Selected tasks"
            className="flex flex-wrap items-center gap-2 border-b border-border bg-brand-subtle/40 px-4 py-2 text-sm"
        >
            {notice !== null && (
                <p
                    role={notice.tone === 'error' ? 'alert' : 'status'}
                    className={cn(
                        'flex w-full items-center gap-2 text-xs',
                        notice.tone === 'error'
                            ? 'text-destructive'
                            : 'text-muted-foreground',
                    )}
                >
                    {notice.text}
                    <button
                        type="button"
                        onClick={() => setNotice(null)}
                        className="ml-auto rounded p-0.5 transition-colors hover:bg-accent"
                    >
                        <X aria-hidden="true" className="size-3.5" />
                        <span className="sr-only">Dismiss</span>
                    </button>
                </p>
            )}

            {count > 0 && (
                <>
                    <span className="font-medium tabular-nums">
                        {count} selected
                    </span>

                    {destinations.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={pending}
                                >
                                    <CalendarRange aria-hidden="true" />
                                    Move to
                                    <ChevronDown aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>

                            <DropdownMenuContent align="start" className="w-56">
                                {destinations.map((sprint) => (
                                    <DropdownMenuItem
                                        key={sprint.id}
                                        onSelect={() =>
                                            void apply({ sprint_id: sprint.id })
                                        }
                                    >
                                        <span className="truncate">
                                            {sprint.name}
                                        </span>
                                        {sprint.state === 'active' && (
                                            <span className="ml-auto text-xs text-muted-foreground">
                                                Active
                                            </span>
                                        )}
                                    </DropdownMenuItem>
                                ))}

                                <DropdownMenuSeparator />

                                <DropdownMenuItem
                                    onSelect={() =>
                                        void apply({ in_backlog: true })
                                    }
                                >
                                    Backlog
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}

                    {agentsEnabled && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={pending}
                                >
                                    <Bot aria-hidden="true" />
                                    Assign to agent
                                    <ChevronDown aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>

                            <DropdownMenuContent align="start" className="w-64">
                                {me !== null && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            void apply({
                                                agent: 'assign',
                                                assignee_id: me.id,
                                            })
                                        }
                                    >
                                        <UserAvatar user={me} size="xs" />
                                        You — starts now
                                    </DropdownMenuItem>
                                )}

                                {others.length > 0 && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                                            Ask for their agent
                                        </DropdownMenuLabel>
                                    </>
                                )}

                                {others.map((membership) => (
                                    <DropdownMenuItem
                                        key={membership.id}
                                        onSelect={() =>
                                            void apply({
                                                agent: 'assign',
                                                assignee_id: membership.user.id,
                                            })
                                        }
                                    >
                                        <UserAvatar
                                            user={membership.user}
                                            size="xs"
                                        />
                                        <span className="truncate">
                                            {membership.user.name}
                                        </span>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}

                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="ml-auto"
                        disabled={pending}
                        onClick={onClear}
                    >
                        <X aria-hidden="true" />
                        Clear selection
                    </Button>
                </>
            )}
        </div>
    );
}

/** "PCA-4 and PCA-9 already had an agent, so they were left as they were." */
function skippedNotice(references: string[]): string {
    const one = references.length === 1;
    const names =
        references.length <= 2
            ? references.join(' and ')
            : `${references.slice(0, -1).join(', ')} and ${references.at(-1)}`;

    return `${names} already had an agent, so ${one ? 'it was' : 'they were'} left as ${one ? 'it was' : 'they were'}.`;
}
