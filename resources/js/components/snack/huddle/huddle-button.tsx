import { Headphones } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { HuddleTarget } from '@/hooks/use-huddle';
import { useHuddle, useIsHuddlingIn } from '@/hooks/use-huddle';
import { cn } from '@/lib/utils';
import type { HuddleContainerRef } from '@/types';

/**
 * The Huddle control in a channel or conversation header.
 *
 * One button for "start" and "join", because from here they are the same
 * intention — put me in the call in this room — and only the server can tell
 * which one it turns out to be without racing. See App\Actions\Huddles\JoinHuddle.
 *
 * Renders nothing when the viewer cannot hold a call here, which includes the
 * case where the deployment has no media server configured at all. That decision
 * arrives as a single `canHuddle` prop precisely so the client never has to
 * reimplement it — see App\Policies\HuddlePolicy.
 */
export function HuddleButton({
    target,
    container,
    canHuddle,
}: {
    target: HuddleTarget;
    container: HuddleContainerRef;
    canHuddle: boolean;
}) {
    const { join, leave, isJoining } = useHuddle();
    const isMine = useIsHuddlingIn(container);

    if (!canHuddle) {
        return null;
    }

    const label = isMine ? 'Leave huddle' : 'Start or join a huddle';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={() => void (isMine ? leave() : join(target))}
                    disabled={isJoining}
                    aria-pressed={isMine}
                    className={cn(
                        'rounded-lg p-2 transition-colors disabled:opacity-60',
                        isMine
                            ? // Filled while you are in the call: this is the
                              // one header control whose state matters from
                              // across the room.
                              'text-brand-foreground bg-brand hover:bg-brand/90'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    )}
                >
                    <Headphones aria-hidden="true" className="size-4" />
                    <span className="sr-only">{label}</span>
                </button>
            </TooltipTrigger>

            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
