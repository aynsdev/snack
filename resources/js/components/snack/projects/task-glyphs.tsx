import {
    Bookmark,
    Bug,
    ChevronDown,
    ChevronsDown,
    ChevronsUp,
    ChevronUp,
    Equal,
    SquareCheck,
    Zap,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { TaskPriority, TaskType } from '@/types';

/**
 * The two glyphs every card in every issue tracker wears: what kind of work it
 * is, and how urgent.
 *
 * Both are drawn from fixed sets rather than from anything a project configures,
 * which is the point — a bug is red and pointing-down means low wherever you
 * look, so a card can be read at a glance across projects. The parts a team does
 * invent are its labels and its columns.
 *
 * Full class names, not interpolated ones: Tailwind only emits classes it can
 * find written out in the source.
 */
export function TaskTypeIcon({
    type,
    className,
}: {
    type: TaskType;
    className?: string;
}) {
    const { icon: Icon, color, label } = TASK_TYPES[type];

    return (
        <span title={label} className="inline-flex">
            <Icon
                aria-hidden="true"
                className={cn('size-3.5 shrink-0', color, className)}
            />
            <span className="sr-only">{label}</span>
        </span>
    );
}

export function TaskPriorityIcon({
    priority,
    className,
}: {
    priority: TaskPriority;
    className?: string;
}) {
    const { icon: Icon, color, label } = TASK_PRIORITIES[priority];

    return (
        <span title={`${label} priority`} className="inline-flex">
            <Icon
                aria-hidden="true"
                className={cn('size-3.5 shrink-0', color, className)}
            />
            <span className="sr-only">{label} priority</span>
        </span>
    );
}

export const TASK_TYPES: Record<
    TaskType,
    { icon: typeof Bug; color: string; label: string }
> = {
    story: { icon: Bookmark, color: 'text-emerald-500', label: 'Story' },
    task: { icon: SquareCheck, color: 'text-blue-500', label: 'Task' },
    bug: { icon: Bug, color: 'text-rose-500', label: 'Bug' },
    epic: { icon: Zap, color: 'text-violet-500', label: 'Epic' },
};

export const TASK_PRIORITIES: Record<
    TaskPriority,
    { icon: typeof ChevronUp; color: string; label: string }
> = {
    highest: { icon: ChevronsUp, color: 'text-rose-500', label: 'Highest' },
    high: { icon: ChevronUp, color: 'text-orange-500', label: 'High' },
    medium: { icon: Equal, color: 'text-amber-500', label: 'Medium' },
    low: { icon: ChevronDown, color: 'text-sky-500', label: 'Low' },
    lowest: { icon: ChevronsDown, color: 'text-slate-400', label: 'Lowest' },
};

/**
 * A label chip.
 *
 * Colours come from the same named palette projects are drawn in, for the reason
 * given in {@see PROJECT_COLORS}: a class name assembled at runtime is a class
 * name Tailwind never emitted.
 */
export function LabelChip({ name, color }: { name: string; color: string }) {
    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center truncate rounded px-1.5 py-0.5 text-[0.625rem] leading-none font-medium',
                LABEL_COLORS[color] ?? LABEL_COLORS.slate,
            )}
        >
            {name}
        </span>
    );
}

export const LABEL_COLORS: Record<string, string> = {
    violet: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    blue: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    cyan: 'bg-cyan-500/15 text-cyan-700 dark:text-cyan-300',
    emerald: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    amber: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    rose: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
    slate: 'bg-slate-500/15 text-slate-700 dark:text-slate-300',
};

export const LABEL_COLOR_NAMES = Object.keys(LABEL_COLORS);
