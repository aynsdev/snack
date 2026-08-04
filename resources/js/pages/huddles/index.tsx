import { Head, Link } from '@inertiajs/react';
import { Headphones } from 'lucide-react';
import {
    ContainerLabel,
    useContainerHref,
} from '@/components/snack/container-label';
import { EmptyState } from '@/components/snack/empty-state';
import { AvatarGroup } from '@/components/snack/user-avatar';
import { dayLabel } from '@/lib/message-grouping';
import { cn } from '@/lib/utils';
import type { HuddleHistoryEntry } from '@/types';

type Props = {
    huddles: HuddleHistoryEntry[];
};

/**
 * Every huddle that has happened where you could see it.
 *
 * A huddle leaves no message behind — that is what makes it lighter than a
 * meeting — so a call missed by ten minutes would otherwise be indistinguishable
 * from one that never happened. This is the record of it: where, when, how long,
 * and who was on it.
 *
 * Not a recording. Snack never holds the media, so there is nothing here to play
 * back; the row links to the container instead, which is where the conversation
 * that outlived the call is.
 */
export default function HuddlesIndex({ huddles }: Props) {
    const live = huddles.filter((huddle) => huddle.is_live).length;

    return (
        <>
            <Head title="Huddles" />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center gap-2 border-b border-border px-4 py-3">
                    <Headphones
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    <h1 className="text-base font-bold tracking-tight">
                        Huddles
                    </h1>

                    {live > 0 && (
                        <span className="rounded-full bg-success/15 px-2 py-0.5 text-[0.6875rem] leading-none font-bold text-success">
                            {live} live
                        </span>
                    )}
                </header>

                <div className="snack-scroll min-h-0 flex-1 overflow-y-auto">
                    {huddles.length === 0 ? (
                        <EmptyState
                            icon={Headphones}
                            title="No huddles yet"
                            description="Start one from any channel or DM to talk instead of typing. Every call shows up here afterwards — where it was, how long it ran, and who was on it."
                        />
                    ) : (
                        <ul className="divide-y divide-border">
                            {huddles.map((huddle) => (
                                <HuddleRow key={huddle.id} huddle={huddle} />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

function HuddleRow({ huddle }: { huddle: HuddleHistoryEntry }) {
    const href = useContainerHref(huddle.container);

    return (
        <li>
            <Link
                href={href ?? '#'}
                className={cn(
                    'flex items-center gap-3 px-4 py-3 transition-colors hover:bg-accent/50',
                    href === null && 'pointer-events-none opacity-60',
                )}
            >
                <span
                    className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-full',
                        huddle.is_live
                            ? 'bg-success/15 text-success'
                            : 'bg-muted text-muted-foreground',
                    )}
                >
                    <Headphones aria-hidden="true" className="size-4" />
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <ContainerLabel container={huddle.container} />

                        {huddle.is_live && (
                            <span className="shrink-0 rounded-full bg-success px-1.5 py-0.5 text-[0.625rem] leading-none font-bold text-background">
                                Live
                            </span>
                        )}

                        <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                            {dayLabel(huddle.started_at)}
                        </span>
                    </div>

                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {huddle.started_by === null
                            ? 'Huddle'
                            : `Started by ${huddle.started_by.name}`}
                        {' · '}
                        {huddle.is_live
                            ? 'happening now'
                            : formatDuration(huddle.duration_seconds)}
                    </p>
                </div>

                {huddle.participants.length > 0 && (
                    <AvatarGroup
                        users={huddle.participants}
                        max={4}
                        size="xs"
                    />
                )}
            </Link>
        </li>
    );
}

/**
 * "12 min", "1 hr 5 min", "48 sec".
 *
 * Rounded to whole units, because nobody asks how many seconds a forty-minute
 * call ran for — and kept at seconds below a minute, because "0 min" reads as a
 * call that did not happen when in fact it is one somebody dialled out of.
 */
function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return 'ended';
    }

    if (seconds < 60) {
        return `${Math.max(seconds, 1)} sec`;
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;

    return remainder === 0 ? `${hours} hr` : `${hours} hr ${remainder} min`;
}
