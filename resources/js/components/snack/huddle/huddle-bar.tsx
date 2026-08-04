import { Headphones, Loader2 } from 'lucide-react';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import type { HuddleTarget } from '@/hooks/use-huddle';
import { useHuddle, useIsHuddlingIn } from '@/hooks/use-huddle';
import type { Huddle } from '@/types';

/**
 * The strip under a channel or DM header announcing a live huddle.
 *
 * Renders nothing when there is no call, rather than an empty bar explaining
 * that huddles exist — the same rule {@see PinnedBar} follows.
 *
 * # Why it stays up while you are in the call
 *
 * The obvious behaviour is to hide the bar once you join, since the tray at the
 * bottom of the screen already says you are in a huddle. It stays because the
 * tray is workspace-wide and the bar is about *this room*: reading #design while
 * huddling in #general, the bar is how you tell that the call you can hear is
 * not the one belonging to what you are looking at. What changes is the button —
 * there is nothing to join.
 */
export function HuddleBar({
    huddle,
    target,
    canJoin,
}: {
    huddle: Huddle | null;
    target: HuddleTarget;
    /**
     * Whether the viewer may join at all. False while previewing a channel they
     * have not joined: they can see the call is happening — the same thing the
     * composer tells them — but joining is a contribution.
     */
    canJoin: boolean;
}) {
    const { join, isJoining } = useHuddle();
    const isMine = useIsHuddlingIn(huddle === null ? null : huddle.container);

    if (huddle === null) {
        return null;
    }

    const people = huddle.participants;

    return (
        <div className="flex items-center gap-3 border-b border-border bg-brand-subtle/40 px-4 py-2">
            <Headphones
                aria-hidden="true"
                className="size-4 shrink-0 text-brand"
            />

            <div className="flex min-w-0 flex-1 items-center gap-2">
                {/*
                 * A stack rather than a list of names: a huddle is capped well
                 * below the point where names would fit, and faces are what
                 * people actually scan for. `-space-x-1.5` overlaps them; the
                 * ring matches the bar's own surface so the overlap reads as
                 * depth rather than as a smudge.
                 */}
                <span className="flex -space-x-1.5">
                    {people.slice(0, 5).map((person) => (
                        <UserAvatar
                            key={person.id}
                            user={person}
                            size="sm"
                            className="ring-2 ring-brand-subtle"
                        />
                    ))}
                </span>

                <p className="min-w-0 truncate text-xs font-medium text-brand">
                    {describe(people.length, isMine)}
                </p>
            </div>

            {isMine ? (
                <span className="shrink-0 text-xs font-medium text-muted-foreground">
                    You are in this huddle
                </span>
            ) : (
                canJoin && (
                    <Button
                        size="sm"
                        onClick={() => void join(target)}
                        disabled={isJoining}
                        className="shrink-0"
                    >
                        {isJoining && (
                            <Loader2
                                aria-hidden="true"
                                className="size-3.5 animate-spin"
                            />
                        )}
                        Join
                    </Button>
                )
            )}
        </div>
    );
}

/**
 * What the bar says about the call.
 *
 * Counts rather than names. The server's roster is what someone outside the call
 * sees, and it lags LiveKit's by up to a heartbeat — naming people invites the
 * reader to notice that the list is a moment behind, while a count of four
 * being briefly a count of three does not read as wrong.
 */
function describe(count: number, isMine: boolean): string {
    if (count === 0) {
        // Briefly true between a huddle being created and its first participant
        // row landing.
        return 'Huddle starting';
    }

    if (isMine) {
        return count === 1
            ? 'Just you in this huddle'
            : `${count} people in this huddle`;
    }

    return count === 1
        ? '1 person is huddling'
        : `${count} people are huddling`;
}
