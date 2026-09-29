import { router, useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { ClockCard } from '@/components/hr/clock-card';
import {
    DeleteButton,
    FormDialog,
    LocationLink,
} from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatMinutes,
    formatTime,
    HrPage,
    PersonAvatar,
    Section,
    SelectField,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    destroy as destroyEntry,
    index as timeIndex,
    update as updateEntry,
} from '@/routes/hr/time';
import type { Geofence, TimeEntry } from '@/types/hr';

type Filters = {
    from: string;
    to: string;
    scope: 'mine' | 'team';
    employee: number | null;
};

type Props = {
    entries: TimeEntry[];
    total_minutes: number;
    open_entry: TimeEntry | null;
    has_employee: boolean;
    geofence: Geofence | null;
    require_location: boolean;
    filters: Filters;
    employees: { id: number; name: string }[];
    can_manage: boolean;
};

/**
 * The time clock and the timesheet it fills. Each shift shows where it was
 * clocked in and out from, green inside the office area and amber outside it.
 */
export default function TimePage({
    entries,
    total_minutes,
    open_entry,
    has_employee,
    geofence,
    require_location,
    filters,
    employees,
    can_manage,
}: Props) {
    const workspace = useWorkspace();
    const [editing, setEditing] = useState<TimeEntry | null>(null);
    const [range, setRange] = useState({ from: filters.from, to: filters.to });

    function apply(next: Partial<Filters>) {
        const query = { ...filters, ...range, ...next };

        router.get(
            timeIndex(workspace.slug).url,
            Object.fromEntries(
                Object.entries(query).filter(
                    ([, value]) => value !== null && value !== '',
                ),
            ),
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    const team = filters.scope === 'team';

    return (
        <HrPage
            title="Time clock"
            description="Clock in when you start and out when you finish. Your location is recorded with each."
        >
            {has_employee && (
                <ClockCard
                    openEntry={open_entry}
                    geofence={geofence}
                    requireLocation={require_location}
                />
            )}

            <Section
                title={team ? 'Team timesheet' : 'My timesheet'}
                action={
                    <span className="text-sm font-semibold tabular-nums">
                        {formatMinutes(total_minutes)} total
                    </span>
                }
            >
                <div className="mb-4 flex flex-wrap items-end gap-2">
                    {can_manage && (
                        <div
                            className="flex rounded-lg border border-border p-0.5"
                            role="group"
                            aria-label="Whose time"
                        >
                            {(['mine', 'team'] as const).map((scope) => (
                                <Button
                                    key={scope}
                                    size="sm"
                                    variant={
                                        filters.scope === scope
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    onClick={() =>
                                        apply({ scope, employee: null })
                                    }
                                >
                                    {scope === 'mine' ? 'Mine' : 'Everyone'}
                                </Button>
                            ))}
                        </div>
                    )}
                    <Field label="From" htmlFor="time-from">
                        <Input
                            id="time-from"
                            type="date"
                            value={range.from}
                            onChange={(event) =>
                                setRange({ ...range, from: event.target.value })
                            }
                            className="w-40"
                        />
                    </Field>
                    <Field label="To" htmlFor="time-to">
                        <Input
                            id="time-to"
                            type="date"
                            value={range.to}
                            onChange={(event) =>
                                setRange({ ...range, to: event.target.value })
                            }
                            className="w-40"
                        />
                    </Field>
                    {team && employees.length > 0 && (
                        <SelectField
                            id="time-employee"
                            label="Employee"
                            value={
                                filters.employee ? String(filters.employee) : ''
                            }
                            onChange={(value) =>
                                apply({
                                    employee:
                                        value === '' ? null : Number(value),
                                })
                            }
                            options={employees.map((person) => ({
                                value: String(person.id),
                                label: person.name,
                            }))}
                            allowEmpty
                            emptyLabel="Everyone"
                            className="w-48"
                        />
                    )}
                    <Button variant="outline" onClick={() => apply({})}>
                        Apply
                    </Button>
                </div>

                {entries.length === 0 ? (
                    <EmptyNote>No time logged in this range.</EmptyNote>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[36rem] text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr>
                                    {team && (
                                        <th
                                            scope="col"
                                            className="py-2 pr-3 font-medium"
                                        >
                                            Employee
                                        </th>
                                    )}
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-medium"
                                    >
                                        Date
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-medium"
                                    >
                                        In
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-medium"
                                    >
                                        Out
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-medium"
                                    >
                                        Location
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 text-right font-medium"
                                    >
                                        Hours
                                    </th>
                                    {can_manage && (
                                        <th scope="col" className="py-2">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {entries.map((entry) => (
                                    <tr key={entry.id}>
                                        {team && (
                                            <td className="py-2 pr-3">
                                                {entry.employee && (
                                                    <span className="flex items-center gap-2">
                                                        <PersonAvatar
                                                            person={
                                                                entry.employee
                                                            }
                                                            className="size-6"
                                                        />
                                                        <span className="truncate">
                                                            {
                                                                entry.employee
                                                                    .name
                                                            }
                                                        </span>
                                                    </span>
                                                )}
                                            </td>
                                        )}
                                        <td className="py-2 pr-3 whitespace-nowrap">
                                            {new Date(
                                                entry.clock_in_at,
                                            ).toLocaleDateString(undefined, {
                                                weekday: 'short',
                                                month: 'short',
                                                day: 'numeric',
                                            })}
                                        </td>
                                        <td className="py-2 pr-3 tabular-nums">
                                            {formatTime(entry.clock_in_at)}
                                        </td>
                                        <td className="py-2 pr-3 tabular-nums">
                                            {entry.is_open ? (
                                                <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                                    Working
                                                </span>
                                            ) : (
                                                formatTime(entry.clock_out_at)
                                            )}
                                        </td>
                                        <td className="py-2 pr-3">
                                            <span className="flex items-center gap-2 text-xs">
                                                <LocationLink
                                                    stamp={
                                                        entry.clock_in_location
                                                    }
                                                    label="In"
                                                />
                                                <LocationLink
                                                    stamp={
                                                        entry.clock_out_location
                                                    }
                                                    label="Out"
                                                />
                                                {!entry.clock_in_location &&
                                                    !entry.clock_out_location && (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                            </span>
                                            {entry.note && (
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {entry.note}
                                                </span>
                                            )}
                                        </td>
                                        <td
                                            className={cn(
                                                'py-2 pr-3 text-right tabular-nums',
                                                entry.is_open &&
                                                    'font-semibold',
                                            )}
                                        >
                                            {formatMinutes(entry.minutes)}
                                        </td>
                                        {can_manage && (
                                            <td className="py-2 text-right whitespace-nowrap">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    aria-label="Correct this shift"
                                                    onClick={() =>
                                                        setEditing(entry)
                                                    }
                                                >
                                                    <Pencil aria-hidden="true" />
                                                </Button>
                                                <DeleteButton
                                                    label="Delete this shift"
                                                    onConfirm={() =>
                                                        router.delete(
                                                            destroyEntry({
                                                                workspace:
                                                                    workspace.slug,
                                                                timeEntry:
                                                                    entry.id,
                                                            }).url,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                />
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Section>

            {editing && (
                <CorrectionDialog
                    key={editing.id}
                    entry={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </HrPage>
    );
}

/** `datetime-local` wants local wall-clock time with no zone. */
function toLocalInput(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function CorrectionDialog({
    entry,
    onClose,
}: {
    entry: TimeEntry;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        clock_in_at: toLocalInput(entry.clock_in_at),
        clock_out_at: toLocalInput(entry.clock_out_at),
        note: entry.note ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        // Sent as absolute instants so the server does not read the admin's
        // wall-clock time in its own timezone.
        form.transform((data) => ({
            clock_in_at: new Date(data.clock_in_at).toISOString(),
            clock_out_at: data.clock_out_at
                ? new Date(data.clock_out_at).toISOString()
                : null,
            note: data.note || null,
        }));
        form.patch(
            updateEntry({ workspace: workspace.slug, timeEntry: entry.id }).url,
            { preserveScroll: true, onSuccess: onClose },
        );
    }

    return (
        <FormDialog
            title="Correct a shift"
            description={
                entry.employee
                    ? `${entry.employee.name}'s shift. The correction is recorded in the audit log.`
                    : 'The correction is recorded in the audit log.'
            }
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Clocked in"
                    htmlFor="entry-in"
                    error={form.errors.clock_in_at}
                >
                    <Input
                        id="entry-in"
                        type="datetime-local"
                        value={form.data.clock_in_at}
                        onChange={(event) =>
                            form.setData('clock_in_at', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Clocked out"
                    htmlFor="entry-out"
                    error={form.errors.clock_out_at}
                >
                    <Input
                        id="entry-out"
                        type="datetime-local"
                        value={form.data.clock_out_at}
                        onChange={(event) =>
                            form.setData('clock_out_at', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Note"
                    htmlFor="entry-note"
                    error={form.errors.note}
                    className="sm:col-span-2"
                >
                    <Input
                        id="entry-note"
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                    />
                </Field>
            </div>
        </FormDialog>
    );
}
