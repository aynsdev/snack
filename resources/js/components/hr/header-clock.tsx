import { Link, usePage } from '@inertiajs/react';
import { Clock, LoaderCircle, LogIn, LogOut } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { index as timeIndex } from '@/routes/hr/time';
import type { HeaderClockState } from '@/types/hr';
import { formatDuration, formatTime } from './hr-ui';
import { useClock, useElapsedSeconds } from './use-clock';

/**
 * The time clock in the shell's header: a "Clock in" button, or — once
 * clocked in — the running time with a menu to clock out.
 *
 * Reads the shared `clock` prop, so it is current on every page and updates
 * with the redirect after any clock action, including ones made from the HR
 * time clock card. Renders nothing for people without an employee file.
 */
export function HeaderClock({ className }: { className?: string }) {
    const clock = usePage().props.clock;

    if (!clock) {
        return null;
    }

    return <HeaderClockControl clock={clock} className={className} />;
}

function HeaderClockControl({
    clock,
    className,
}: {
    clock: HeaderClockState;
    className?: string;
}) {
    const workspace = useWorkspace();
    const since = clock.open_entry?.clock_in_at ?? null;
    const isIn = since !== null;
    const elapsed = formatDuration(useElapsedSeconds(since));
    const { submit, submitting, location, error } = useClock(isIn);

    // The header has no room for an inline error, so failures surface as a
    // toast — the same place the success message lands.
    useEffect(() => {
        if (error) {
            toast.error(error, { id: `clock-error-${error}` });
        }
    }, [error]);

    function run() {
        submit(undefined, (message) => {
            if (message) {
                // Same id the HR pages' flash toasts use, so a clock action
                // taken on an HR page does not announce itself twice.
                toast.success(message, { id: `hr-success-${message}` });
            }
        });
    }

    const busyLabel =
        location.state === 'locating' ? 'Getting location…' : 'Saving…';

    if (!isIn) {
        return (
            <button
                type="button"
                onClick={run}
                disabled={submitting}
                title="Clock in — your location is recorded"
                className={cn(
                    'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-border px-2.5 text-xs font-medium transition-colors hover:bg-sidebar-accent disabled:opacity-60',
                    className,
                )}
            >
                {submitting ? (
                    <LoaderCircle
                        aria-hidden="true"
                        className="size-3.5 animate-spin"
                    />
                ) : (
                    <LogIn aria-hidden="true" className="size-3.5" />
                )}
                {submitting ? busyLabel : 'Clock in'}
            </button>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className={cn(
                    'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg bg-emerald-500/15 px-2.5 text-xs font-semibold text-emerald-700 tabular-nums transition-colors hover:bg-emerald-500/25 dark:text-emerald-300',
                    className,
                )}
                // The time is left out: a label that changed every second
                // would be re-announced by screen readers.
                aria-label="Clocked in. Open time clock menu"
            >
                {submitting ? (
                    <LoaderCircle
                        aria-hidden="true"
                        className="size-3.5 animate-spin"
                    />
                ) : (
                    <span aria-hidden="true" className="relative flex size-2">
                        <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-60 motion-reduce:animate-none" />
                        <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
                    </span>
                )}
                {submitting ? (
                    busyLabel
                ) : (
                    <time aria-hidden="true">{elapsed}</time>
                )}
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="font-normal">
                    <span className="block text-sm font-semibold">
                        Clocked in ·{' '}
                        <span className="tabular-nums">{elapsed}</span>
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        Since {formatTime(since)}
                    </span>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={run} disabled={submitting}>
                    <LogOut className="mr-2 size-4" />
                    Clock out
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={timeIndex(workspace.slug)}>
                        <Clock className="mr-2 size-4" />
                        My timesheet
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
