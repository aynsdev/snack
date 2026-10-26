import { Bot, CircleStop, Play, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCurrentUser } from '@/hooks/use-workspace';
import { agentStateOf } from '@/lib/agent-labels';
import type { AgentState } from '@/lib/agent-labels';
import { cn } from '@/lib/utils';
import type { ProjectMembership, Task, TaskAgentIntent } from '@/types';

/**
 * Hands a card to somebody's agent.
 *
 * Only the assignee can start their own agent, so assigning yourself starts it
 * and assigning anybody else only asks; they press Start. The server applies
 * the same rule — see ResolveTaskAgentIntent.
 */
export function TaskAgentControls({
    task,
    members,
    onSave,
}: {
    task: Task;
    members: ProjectMembership[];
    onSave: (attributes: Record<string, unknown>) => Promise<unknown>;
}) {
    const me = useCurrentUser();
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const state = agentStateOf(task.labels);
    const assignee = task.assignee ?? null;
    const isAssignee = me !== null && assignee?.id === me.id;

    const others = useMemo(
        () => members.filter((membership) => membership.user.id !== me?.id),
        [members, me?.id],
    );

    const send = useCallback(
        async (agent: TaskAgentIntent, assigneeId?: number) => {
            setPending(true);
            setError(null);

            try {
                await onSave(
                    assigneeId === undefined
                        ? { agent }
                        : { agent, assignee_id: assigneeId },
                );
            } catch (caught) {
                setError(
                    caught instanceof Error
                        ? caught.message
                        : 'The agent could not be updated.',
                );
            } finally {
                setPending(false);
            }
        },
        [onSave],
    );

    const status =
        state === 'active'
            ? 'Agent working'
            : state === 'requested'
              ? isAssignee
                  ? 'Agent requested — start it when ready'
                  : `Agent requested — waiting for ${assignee?.name ?? 'the assignee'}`
              : 'No agent';

    return (
        <div className="space-y-1">
            <div className="flex h-9 w-full items-center gap-2 rounded-md border border-input bg-transparent pr-1 pl-3 text-sm shadow-xs dark:bg-input/30">
                <span
                    aria-hidden="true"
                    className={cn(
                        'size-2 shrink-0 rounded-full',
                        STATUS_DOTS[state ?? 'none'],
                    )}
                />
                <Bot
                    aria-hidden="true"
                    className="size-4 shrink-0 text-muted-foreground"
                />
                <span
                    className={cn(
                        'min-w-0 flex-1 truncate',
                        state === null && 'text-muted-foreground',
                    )}
                    title={status}
                >
                    {status}
                </span>

                {state === null &&
                    (assignee !== null ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="h-7 px-2 text-xs"
                            disabled={pending}
                            onClick={() => void send('assign')}
                        >
                            Assign to agent
                        </Button>
                    ) : (
                        <Select
                            disabled={pending}
                            onValueChange={(value) =>
                                void send('assign', Number(value))
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                aria-label="Assign to agent"
                                className="h-7 border-0 bg-transparent px-2 text-xs shadow-none dark:bg-transparent"
                            >
                                <SelectValue placeholder="Assign to agent" />
                            </SelectTrigger>

                            <SelectContent align="end">
                                {me !== null && (
                                    <SelectItem value={String(me.id)}>
                                        <span className="flex items-center gap-2">
                                            <UserAvatar user={me} size="xs" />
                                            You — starts now
                                        </span>
                                    </SelectItem>
                                )}

                                {others.map((membership) => (
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
                    ))}

                {state === 'requested' && isAssignee && (
                    <Button
                        type="button"
                        size="sm"
                        className="h-7 px-2 text-xs"
                        disabled={pending}
                        onClick={() => void send('start')}
                    >
                        <Play aria-hidden="true" className="size-3.5" />
                        Start agent
                    </Button>
                )}

                {(state === 'active' ||
                    (state === 'requested' && !isAssignee)) && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-7 px-2 text-xs"
                        disabled={pending}
                        onClick={() => void send('stop')}
                    >
                        {state === 'active' ? (
                            <CircleStop
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        ) : (
                            <X aria-hidden="true" className="size-3.5" />
                        )}
                        {state === 'active' ? 'Stop' : 'Cancel request'}
                    </Button>
                )}
            </div>

            {error !== null && (
                <p role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

const STATUS_DOTS: Record<AgentState | 'none', string> = {
    active: 'bg-emerald-500',
    requested: 'bg-amber-500',
    none: 'bg-muted-foreground/40',
};
