import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DeleteButton, FormDialog } from '@/components/hr/hr-dialogs';
import { EmptyNote, Field, Section } from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { destroy, store, update } from '@/routes/hr/shifts';
import type { Shift } from '@/types/hr';

/** ISO weekdays, Monday first. */
export const WEEKDAYS = [
    { value: 1, short: 'Mon' },
    { value: 2, short: 'Tue' },
    { value: 3, short: 'Wed' },
    { value: 4, short: 'Thu' },
    { value: 5, short: 'Fri' },
    { value: 6, short: 'Sat' },
    { value: 7, short: 'Sun' },
];

/** "Mon–Fri", "Mon, Wed, Fri", "Every day". */
export function formatWeekdays(days: number[]): string {
    if (days.length === 7) {
        return 'Every day';
    }

    const sorted = [...days].sort((a, b) => a - b);
    const consecutive = sorted.every(
        (day, index) => index === 0 || day === sorted[index - 1] + 1,
    );
    const name = (day: number) =>
        WEEKDAYS.find((weekday) => weekday.value === day)?.short ?? '';

    return consecutive && sorted.length > 2
        ? `${name(sorted[0])}–${name(sorted[sorted.length - 1])}`
        : sorted.map(name).join(', ');
}

/**
 * The shifts people are scheduled on, in HR settings. Lateness and leaving
 * early are measured against these.
 */
export function ShiftsSection({ shifts }: { shifts: Shift[] }) {
    const workspace = useWorkspace();
    const [editing, setEditing] = useState<Shift | 'new' | null>(null);

    return (
        <Section
            title="Shifts"
            action={
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setEditing('new')}
                >
                    <Plus aria-hidden="true" />
                    Add shift
                </Button>
            }
        >
            {shifts.length === 0 ? (
                <EmptyNote>
                    No shifts yet. Add one to start measuring late arrivals and
                    early departures.
                </EmptyNote>
            ) : (
                <ul className="divide-y divide-border">
                    {shifts.map((shift) => (
                        <li
                            key={shift.id}
                            className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium">
                                    {shift.name}
                                    {shift.is_default && (
                                        <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs font-normal text-muted-foreground">
                                            Default
                                        </span>
                                    )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {shift.hours} · {formatWeekdays(shift.days)}
                                    {shift.grace_minutes > 0 &&
                                        ` · ${shift.grace_minutes} min grace`}
                                </p>
                            </div>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label={`Edit ${shift.name}`}
                                onClick={() => setEditing(shift)}
                            >
                                <Pencil aria-hidden="true" />
                            </Button>
                            <DeleteButton
                                label={`Delete ${shift.name}`}
                                onConfirm={() =>
                                    router.delete(
                                        destroy({
                                            workspace: workspace.slug,
                                            shift: shift.id,
                                        }).url,
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        </li>
                    ))}
                </ul>
            )}
            <p className="mt-3 text-xs text-muted-foreground">
                People without a shift of their own work the default one. A
                shift that ends before it starts runs past midnight.
            </p>

            {editing !== null && (
                <ShiftDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    shift={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </Section>
    );
}

function ShiftDialog({
    shift,
    onClose,
}: {
    shift: Shift | null;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        name: shift?.name ?? '',
        starts_at: shift?.starts_at ?? '09:00',
        ends_at: shift?.ends_at ?? '18:00',
        grace_minutes: String(shift?.grace_minutes ?? 10),
        days: shift?.days ?? [1, 2, 3, 4, 5],
        is_default: shift?.is_default ?? false,
    });

    function toggleDay(day: number) {
        form.setData(
            'days',
            form.data.days.includes(day)
                ? form.data.days.filter((value) => value !== day)
                : [...form.data.days, day],
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            grace_minutes: Number(data.grace_minutes),
        }));

        if (shift) {
            form.patch(
                update({ workspace: workspace.slug, shift: shift.id }).url,
                { preserveScroll: true, onSuccess: onClose },
            );
        } else {
            form.post(store(workspace.slug).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });
        }
    }

    return (
        <FormDialog
            title={shift ? 'Edit shift' : 'Add a shift'}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <Field label="Name" htmlFor="shift-name" error={form.errors.name}>
                <Input
                    id="shift-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    placeholder="Day shift"
                    required
                />
            </Field>
            <div className="grid gap-4 sm:grid-cols-3">
                <Field
                    label="Starts"
                    htmlFor="shift-starts"
                    error={form.errors.starts_at}
                >
                    <Input
                        id="shift-starts"
                        type="time"
                        value={form.data.starts_at}
                        onChange={(event) =>
                            form.setData('starts_at', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Ends"
                    htmlFor="shift-ends"
                    error={form.errors.ends_at}
                >
                    <Input
                        id="shift-ends"
                        type="time"
                        value={form.data.ends_at}
                        onChange={(event) =>
                            form.setData('ends_at', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Grace (min)"
                    htmlFor="shift-grace"
                    error={form.errors.grace_minutes}
                >
                    <Input
                        id="shift-grace"
                        type="number"
                        min={0}
                        max={240}
                        value={form.data.grace_minutes}
                        onChange={(event) =>
                            form.setData('grace_minutes', event.target.value)
                        }
                        required
                    />
                </Field>
            </div>
            <fieldset>
                <legend className="mb-2 text-sm font-medium">Days</legend>
                <div className="flex flex-wrap gap-1.5">
                    {WEEKDAYS.map((day) => {
                        const selected = form.data.days.includes(day.value);

                        return (
                            <button
                                key={day.value}
                                type="button"
                                aria-pressed={selected}
                                onClick={() => toggleDay(day.value)}
                                className={cn(
                                    'rounded-md border px-2.5 py-1 text-xs font-medium transition-colors',
                                    selected
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border text-muted-foreground hover:bg-accent',
                                )}
                            >
                                {day.short}
                            </button>
                        );
                    })}
                </div>
                {form.errors.days && (
                    <p className="mt-1 text-sm text-destructive">
                        {form.errors.days}
                    </p>
                )}
            </fieldset>
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={form.data.is_default}
                    onCheckedChange={(checked) =>
                        form.setData('is_default', checked === true)
                    }
                />
                Default shift for anybody without one
            </label>
        </FormDialog>
    );
}
