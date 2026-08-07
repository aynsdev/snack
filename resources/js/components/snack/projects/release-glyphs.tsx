import { cn } from '@/lib/utils';
import type { Release } from '@/types';

/**
 * Whether a version has gone out, as one word.
 *
 * Three readings from two facts: released, unreleased, and unreleased with the
 * day gone by. Overdue is drawn as its own state rather than as a decoration on
 * the date, because "late" is the thing somebody scanning this table is looking
 * for and a red date is easy to miss in a column of dates.
 */
export function ReleaseStatus({ release }: { release: Release }) {
    const [label, tone] = release.is_overdue
        ? ['Overdue', 'bg-rose-500/15 text-rose-600 dark:text-rose-300']
        : release.state === 'released'
          ? [
                'Released',
                'bg-emerald-500/15 text-emerald-600 dark:text-emerald-300',
            ]
          : ['Unreleased', 'bg-muted text-muted-foreground'];

    return (
        <span
            className={cn(
                'inline-block rounded px-1.5 py-0.5 text-[0.625rem] font-semibold tracking-wide uppercase',
                tone,
            )}
        >
            {label}
        </span>
    );
}

/**
 * How much of a release is finished.
 *
 * A bar rather than a fraction, because the column is read by scanning it — the
 * numbers are in the title attribute for anyone who wants them, and in the
 * label for screen readers. An empty release draws an empty track rather than a
 * full one: nothing done out of nothing is not finished.
 */
export function ReleaseProgress({
    done,
    total,
    className,
}: {
    done: number;
    total: number;
    className?: string;
}) {
    const percent = total === 0 ? 0 : Math.round((done / total) * 100);

    return (
        <div
            role="progressbar"
            aria-valuenow={percent}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label={`${done} of ${total} done`}
            title={`${done} of ${total} done`}
            className={cn(
                'h-2 w-full overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                style={{ width: `${percent}%` }}
                className="h-full rounded-full bg-emerald-500 transition-[width]"
            />
        </div>
    );
}

/**
 * "Jul 20, 2026", or nothing at all.
 *
 * Parsed as a local date rather than through Date's UTC handling of a bare
 * "YYYY-MM-DD", which renders the day before for anybody west of Greenwich.
 */
export function formatReleaseDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
