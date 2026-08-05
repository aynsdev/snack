import { cn } from '@/lib/utils';
import type { Project } from '@/types';

/**
 * A project's square, in its own colour.
 *
 * Square rather than round, which is the one visual rule that keeps a project
 * from reading as a person anywhere the two appear in the same list.
 *
 * # Why the colours are a lookup and not a class string
 *
 * `bg-${project.color}-500` would be built at runtime, and Tailwind's compiler
 * only emits classes it can find in the source — so every one of these would be
 * missing from the stylesheet and every project would render transparent. The
 * full class name has to appear literally somewhere, which is what this map is.
 */
export function ProjectAvatar({
    project,
    size = 'md',
    className,
}: {
    project: Pick<Project, 'key' | 'color' | 'name'>;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex shrink-0 items-center justify-center rounded-md font-bold text-white uppercase',
                PROJECT_COLORS[project.color] ?? PROJECT_COLORS.violet,
                SIZES[size],
                className,
            )}
        >
            {/* Two letters: the key is up to ten characters and the square is
                not a place to read one. */}
            {project.key.slice(0, 2)}
        </span>
    );
}

const SIZES: Record<'sm' | 'md' | 'lg', string> = {
    sm: 'size-5 text-[0.5625rem]',
    md: 'size-6 text-[0.625rem]',
    lg: 'size-10 text-sm',
};

/**
 * The palette a project can be drawn in.
 *
 * Also the source of truth for the picker on the settings screen — see
 * {@see PROJECT_COLOR_NAMES} — so adding a colour here is the only edit needed
 * to offer it.
 */
export const PROJECT_COLORS: Record<string, string> = {
    violet: 'bg-violet-500',
    blue: 'bg-blue-500',
    cyan: 'bg-cyan-500',
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
    rose: 'bg-rose-500',
    slate: 'bg-slate-500',
};

export const PROJECT_COLOR_NAMES = Object.keys(PROJECT_COLORS);
