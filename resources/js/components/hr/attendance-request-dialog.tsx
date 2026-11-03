import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormDialog } from '@/components/hr/hr-dialogs';
import {
    Field,
    formatDate,
    formatTime,
    SelectField,
    toLocalInput,
} from '@/components/hr/hr-ui';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { store as storeAttendanceRequest } from '@/routes/hr/requests/attendance';
import type { TimeEntry } from '@/types/hr';

function entryLabel(entry: TimeEntry): string {
    const day = formatDate(entry.clock_in_at.slice(0, 10), {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
    const out = entry.is_open
        ? 'not clocked out'
        : formatTime(entry.clock_out_at);

    return `${day}, ${formatTime(entry.clock_in_at)} – ${out}`;
}

/**
 * Asks HR to correct one of your shifts — clocked wrong, or never clocked out
 * of — or to add one you forgot to clock at all. HR's approval writes it onto
 * the timesheet.
 */
export function AttendanceRequestDialog({
    entries,
    entry,
    onClose,
}: {
    entries: TimeEntry[];
    /** The shift to start with; none asks for a missing one. */
    entry?: TimeEntry | null;
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        time_entry_id: entry ? String(entry.id) : '',
        clock_in_at: entry ? toLocalInput(entry.clock_in_at) : '',
        clock_out_at: entry?.clock_out_at
            ? toLocalInput(entry.clock_out_at)
            : '',
        reason: '',
    });

    function pickEntry(value: string) {
        const picked = entries.find(
            (candidate) => String(candidate.id) === value,
        );

        form.setData({
            ...form.data,
            time_entry_id: value,
            clock_in_at: picked ? toLocalInput(picked.clock_in_at) : '',
            clock_out_at: picked?.clock_out_at
                ? toLocalInput(picked.clock_out_at)
                : '',
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        // Sent as absolute instants so the server does not read the
        // employee's wall-clock time in its own timezone.
        form.transform((data) => ({
            time_entry_id:
                data.time_entry_id === '' ? null : Number(data.time_entry_id),
            clock_in_at: new Date(data.clock_in_at).toISOString(),
            clock_out_at: new Date(data.clock_out_at).toISOString(),
            reason: data.reason,
        }));
        form.post(storeAttendanceRequest(workspace.slug).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="Request a time change"
            description="Forgot to clock in or out, or a time needs explaining? HR reviews it before your timesheet changes."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Send request"
        >
            <SelectField
                id="attendance-entry"
                label="Shift"
                value={form.data.time_entry_id}
                onChange={pickEntry}
                options={entries.map((candidate) => ({
                    value: String(candidate.id),
                    label: entryLabel(candidate),
                }))}
                error={form.errors.time_entry_id}
                allowEmpty
                emptyLabel="Add a missing shift"
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label="Clocked in"
                    htmlFor="attendance-in"
                    error={form.errors.clock_in_at}
                >
                    <Input
                        id="attendance-in"
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
                    htmlFor="attendance-out"
                    error={form.errors.clock_out_at}
                >
                    <Input
                        id="attendance-out"
                        type="datetime-local"
                        value={form.data.clock_out_at}
                        onChange={(event) =>
                            form.setData('clock_out_at', event.target.value)
                        }
                        required
                    />
                </Field>
            </div>
            <Field
                label="Reason"
                htmlFor="attendance-reason"
                error={form.errors.reason}
            >
                <Textarea
                    id="attendance-reason"
                    rows={3}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    placeholder="What happened?"
                    required
                />
            </Field>
        </FormDialog>
    );
}
