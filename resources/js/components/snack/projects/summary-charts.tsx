import { cn } from '@/lib/utils';

/**
 * The pieces the project summary is drawn from.
 *
 * # Why there is no charting library
 *
 * Three shapes are needed here — a stat tile, one stacked bar, and rows of
 * bars — and all three are a div with a width. A charting library would be a
 * new dependency, a bundle, and an SVG renderer to make rectangles that CSS
 * already makes, with none of it reusable for the next chart either.
 *
 * # Colour
 *
 * One sequential ramp, `--viz-ramp-from` → `--viz-ramp-to` in app.css: one hue,
 * light to dark, its own steps chosen for each surface and both ends measured
 * against it. Segments are mixed between the two ends in oklab so any number of
 * columns gets an even ramp. Nothing here carries identity by colour alone —
 * every value is printed beside its bar and every series is named.
 */

/**
 * A step of the shared ramp, `position` running 0 (first) → 1 (last).
 *
 * Mixed in CSS rather than computed in JS because the two ends are theme
 * tokens: the browser re-mixes them when the viewer switches to dark, and
 * nothing here has to know which theme is on.
 */
export function rampStep(position: number): string {
    const percent = Math.round(Math.min(1, Math.max(0, position)) * 100);

    return `color-mix(in oklab, var(--viz-ramp-to) ${percent}%, var(--viz-ramp-from))`;
}

/**
 * One headline number and what it counts.
 *
 * A tile rather than a one-bar chart: a single current value is a number, and
 * drawing it as a bar gives the reader a shape to compare against nothing.
 */
export function StatTile({
    label,
    value,
    hint,
    icon: Icon,
}: {
    label: string;
    value: number;
    hint: string;
    icon: React.ComponentType<{ className?: string }>;
}) {
    return (
        <div className="flex items-center gap-3 rounded-xl border border-border p-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon className="size-4" />
            </span>

            <span className="min-w-0">
                <span className="block text-lg leading-tight font-semibold tabular-nums">
                    {value} <span className="text-sm font-medium">{label}</span>
                </span>
                <span className="block text-xs text-balance text-muted-foreground">
                    {hint}
                </span>
            </span>
        </div>
    );
}

export type Segment = {
    key: string | number;
    label: string;
    count: number;
};

/**
 * Part-to-whole as one horizontal stacked bar, in board order.
 *
 * Not a donut: a board can carry any number of statuses, and slices of a circle
 * stop being comparable past a handful. A stacked bar keeps left-to-right
 * meaning the same thing the board does — the work moves that way — and the
 * legend beneath it carries the names and the counts, so nothing depends on
 * telling two similar blues apart.
 *
 * Empty statuses are kept in the legend and drop out of the bar, which is how
 * "nothing is in review" gets said instead of silently disappearing.
 */
export function StackedBar({
    segments,
    total,
}: {
    segments: Segment[];
    total: number;
}) {
    /*
     * A status keeps its colour whether or not it has anything in it.
     *
     * The step is taken from the position in the *whole* list, not among the
     * ones that happen to be non-empty: colour follows the status, never its
     * rank among the survivors. Stepping the drawn segments instead would mean
     * the legend's swatch and the bar disagreed about the same status, and
     * filing one card into an empty column would repaint the whole chart.
     */
    const stepped = segments.map((segment, index) => ({
        ...segment,
        color: rampStep(
            segments.length === 1 ? 1 : index / (segments.length - 1),
        ),
    }));

    const filled = stepped.filter((segment) => segment.count > 0);

    return (
        <div>
            <div
                role="img"
                aria-label={segments
                    .map((segment) => `${segment.label}: ${segment.count}`)
                    .join(', ')}
                className="flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-muted"
            >
                {filled.map((segment) => (
                    <span
                        key={segment.key}
                        title={`${segment.label}: ${segment.count}`}
                        style={{
                            width: `${(segment.count / total) * 100}%`,
                            background: segment.color,
                        }}
                        className="first:rounded-l-full last:rounded-r-full"
                    />
                ))}
            </div>

            <ul className="mt-3 grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
                {stepped.map((segment) => (
                    <li
                        key={segment.key}
                        className="flex items-center gap-2 text-sm"
                    >
                        <span
                            aria-hidden="true"
                            style={{ background: segment.color }}
                            className="size-2.5 shrink-0 rounded-[3px]"
                        />
                        <span className="min-w-0 flex-1 truncate text-muted-foreground">
                            {segment.label}
                        </span>
                        <span className="shrink-0 font-medium tabular-nums">
                            {segment.count}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Rows of bars for comparing magnitude — priorities, types of work.
 *
 * Scaled against the largest row rather than the total, because the question is
 * "which of these is biggest", and against a total every row of a long tail
 * collapses into the same invisible sliver. The share of the whole is printed
 * instead, which is the number that would have been read off such a bar anyway.
 */
export function BarRows({
    rows,
    total,
    emptyLabel,
}: {
    rows: Segment[];
    total: number;
    emptyLabel: string;
}) {
    const largest = Math.max(...rows.map((row) => row.count), 0);

    if (total === 0) {
        return <p className="text-sm text-muted-foreground">{emptyLabel}</p>;
    }

    return (
        <ul className="space-y-2.5">
            {rows.map((row, index) => (
                <li key={row.key} className="flex items-center gap-3">
                    <span className="w-20 shrink-0 truncate text-sm text-muted-foreground">
                        {row.label}
                    </span>

                    <span className="flex min-w-0 flex-1 items-center gap-2">
                        <span className="h-2.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
                            <span
                                title={`${row.label}: ${row.count}`}
                                style={{
                                    width:
                                        largest === 0
                                            ? '0%'
                                            : `${(row.count / largest) * 100}%`,
                                    background: rampStep(
                                        rows.length === 1
                                            ? 1
                                            : index / (rows.length - 1),
                                    ),
                                }}
                                className="block h-full rounded-full"
                            />
                        </span>

                        <span
                            className={cn(
                                'w-16 shrink-0 text-right text-xs tabular-nums',
                                row.count === 0
                                    ? 'text-muted-foreground'
                                    : 'text-foreground',
                            )}
                        >
                            {row.count}
                            <span className="text-muted-foreground">
                                {' '}
                                · {Math.round((row.count / total) * 100)}%
                            </span>
                        </span>
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** A panel on the summary: a heading, a sentence, and one figure. */
export function Panel({
    title,
    description,
    children,
    className,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn('rounded-xl border border-border p-4', className)}
        >
            <h2 className="text-base font-semibold">{title}</h2>
            <p className="mt-0.5 mb-4 text-sm text-muted-foreground">
                {description}
            </p>
            {children}
        </section>
    );
}
