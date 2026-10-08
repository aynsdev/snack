import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/hr';

/**
 * Surfaces the `success` / `error` flash an HR action redirects back with.
 *
 * The id makes the toast idempotent, so strict mode's double effect does not
 * show the same message twice.
 */
export function useFlashToasts(): void {
    const { flash } = usePage().props;

    useEffect(() => {
        if (flash.success) {
            toast.success(flash.success, { id: `hr-success-${flash.success}` });
        }

        if (flash.error) {
            toast.error(flash.error, { id: `hr-error-${flash.error}` });
        }
    }, [flash]);
}

/**
 * The frame every HR page sits in: a scrolling column with a title row.
 */
export function HrPage({
    title,
    description,
    actions,
    children,
    width = 'max-w-5xl',
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    width?: string;
}) {
    const workspace = useWorkspace();

    useFlashToasts();

    return (
        <>
            <Head title={`${title} · ${workspace.name}`} />

            <div className="snack-scroll min-w-0 flex-1 overflow-y-auto">
                <div
                    className={cn(
                        'mx-auto space-y-6 px-4 py-6 sm:px-6 sm:py-8',
                        width,
                    )}
                >
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0">
                            <h1 className="text-2xl font-bold tracking-tight">
                                {title}
                            </h1>
                            {description && (
                                <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>

                        {actions && (
                            <div className="flex flex-wrap items-center gap-2">
                                {actions}
                            </div>
                        )}
                    </header>

                    {children}
                </div>
            </div>
        </>
    );
}

export function Section({
    title,
    action,
    children,
    className,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'rounded-xl border border-border bg-card p-4 sm:p-5',
                className,
            )}
        >
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}

export function PersonAvatar({
    person,
    className,
}: {
    person: { name: string; initials: string; avatar_url: string | null };
    className?: string;
}) {
    return (
        <Avatar className={cn('size-8 rounded-lg', className)}>
            {person.avatar_url && (
                <AvatarImage src={person.avatar_url} alt="" />
            )}
            <AvatarFallback className="rounded-lg bg-brand-subtle text-xs font-semibold text-foreground">
                {person.initials}
            </AvatarFallback>
        </Avatar>
    );
}

export function EmptyNote({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}

const TONES: Record<string, string> = {
    pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    approved: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    active: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    probation: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    on_leave: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    draft: 'bg-slate-500/15 text-slate-700 dark:text-slate-300',
    rejected: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
    terminated: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
    warning: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
    commendation: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    expired: 'bg-slate-500/15 text-slate-700 dark:text-slate-300',
    cancelled: 'bg-slate-500/15 text-slate-700 dark:text-slate-300',
};

export function StatusPill({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-medium',
                TONES[status] ?? 'bg-muted text-muted-foreground',
            )}
        >
            {label}
        </span>
    );
}

export const LEAVE_COLORS: Record<string, string> = {
    sky: 'bg-sky-500',
    rose: 'bg-rose-500',
    amber: 'bg-amber-500',
    emerald: 'bg-emerald-500',
    violet: 'bg-violet-500',
    slate: 'bg-slate-500',
};

export function Field({
    label,
    htmlFor,
    error,
    children,
    className,
}: {
    label: string;
    htmlFor?: string;
    error?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

/** An empty-string sentinel Radix Select can carry for "none". */
const NONE = '__none__';

export function SelectField({
    id,
    label,
    value,
    onChange,
    options,
    error,
    placeholder = 'Choose…',
    allowEmpty = false,
    emptyLabel = 'None',
    className,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Option[];
    error?: string;
    placeholder?: string;
    allowEmpty?: boolean;
    emptyLabel?: string;
    className?: string;
}) {
    return (
        <Field label={label} htmlFor={id} error={error} className={className}>
            <Select
                value={value === '' ? (allowEmpty ? NONE : undefined) : value}
                onValueChange={(next) => onChange(next === NONE ? '' : next)}
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {allowEmpty && (
                        <SelectItem value={NONE}>{emptyLabel}</SelectItem>
                    )}
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}

/** Parse a `YYYY-MM-DD` as a local calendar date, not UTC midnight. */
export function parseDate(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day);
}

export function formatDate(
    date: string | null | undefined,
    options: Intl.DateTimeFormatOptions = {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    },
): string {
    if (!date) {
        return '—';
    }

    return parseDate(date).toLocaleDateString(undefined, options);
}

export function formatDateRange(from: string, to: string): string {
    if (from === to) {
        return formatDate(from);
    }

    return `${formatDate(from, { month: 'short', day: 'numeric' })} – ${formatDate(to)}`;
}

export function formatTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function formatMinutes(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return hours === 0
        ? `${rest}m`
        : `${hours}h ${String(rest).padStart(2, '0')}m`;
}

/** A running timer as `HH:MM:SS`; hours grow past two digits as needed. */
export function formatDuration(totalSeconds: number): string {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
}

export function formatDays(days: number): string {
    return `${Number.isInteger(days) ? days : days.toFixed(1)} ${days === 1 ? 'day' : 'days'}`;
}

/** Today in the viewer's timezone, as `YYYY-MM-DD`. */
export function todayString(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

export function mapsUrl(latitude: number, longitude: number): string {
    return `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=17/${latitude}/${longitude}`;
}

/** `datetime-local` wants local wall-clock time with no zone. */
export function toLocalInput(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
