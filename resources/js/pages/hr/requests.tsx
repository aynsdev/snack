import { router, useForm } from '@inertiajs/react';
import { CalendarClock, Check, ClipboardPen, House, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { FormDialog } from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatDate,
    formatTime,
    HrPage,
    PersonAvatar,
    Section,
    SelectField,
    StatusPill,
    todayString,
    toLocalInput,
} from '@/components/hr/hr-ui';
import { formatWeekdays } from '@/components/hr/shifts-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import * as attendanceRoutes from '@/routes/hr/requests/attendance';
import * as shiftRoutes from '@/routes/hr/requests/shift';
import * as workTypeRoutes from '@/routes/hr/requests/work-type';
import type {
    HrRequest,
    HrRequestType,
    Option,
    Shift,
    TimeEntry,
} from '@/types/hr';

type Props = {
    schedule: {
        today_shift: Shift | null;
        today_works: boolean;
        today_work_type: string;
        work_type: string;
        work_type_label: string;
        pending: number;
    } | null;
    my_requests: HrRequest[];
    queue: HrRequest[];
    shifts: Shift[];
    work_types: Option[];
    recent_entries: TimeEntry[];
    can_request: boolean;
    can_manage: boolean;
};

type Dialog =
    | { kind: 'shift' | 'work_type' | 'attendance' }
    | { kind: 'review'; request: HrRequest; decision: 'approve' | 'reject' };

const ROUTES = {
    shift: shiftRoutes,
    work_type: workTypeRoutes,
    attendance: attendanceRoutes,
} as const;

function requestUrl(
    workspace: string,
    request: HrRequest,
    action: 'cancel' | 'approve' | 'reject',
): string {
    return ROUTES[request.type][action]([workspace, request.id]).url;
}

/**
 * Shift, work-type, and attendance requests: asking for one, following your
 * own, and — for HR — deciding everybody's.
 */
export default function RequestsPage({
    schedule,
    my_requests,
    queue,
    shifts,
    work_types,
    recent_entries,
    can_request,
    can_manage,
}: Props) {
    const workspace = useWorkspace();
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const close = () => setDialog(null);

    return (
        <HrPage
            title="Requests"
            description="Ask HR for a different shift, to work from elsewhere, or to fix your timesheet."
            actions={
                can_request && (
                    <>
                        <Button
                            variant="outline"
                            onClick={() => setDialog({ kind: 'shift' })}
                            disabled={shifts.length === 0}
                        >
                            <CalendarClock aria-hidden="true" />
                            Change shift
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => setDialog({ kind: 'work_type' })}
                        >
                            <House aria-hidden="true" />
                            Change work type
                        </Button>
                        <Button
                            onClick={() => setDialog({ kind: 'attendance' })}
                        >
                            <ClipboardPen aria-hidden="true" />
                            Fix attendance
                        </Button>
                    </>
                )
            }
        >
            {schedule && (
                <Section title="Your schedule today">
                    <dl className="grid gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Shift
                            </dt>
                            <dd className="mt-0.5 font-medium">
                                {schedule.today_shift
                                    ? schedule.today_works
                                        ? `${schedule.today_shift.name} · ${schedule.today_shift.hours}`
                                        : `Day off (${schedule.today_shift.name} runs ${formatWeekdays(schedule.today_shift.days)})`
                                    : 'No shift set'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Working
                            </dt>
                            <dd className="mt-0.5 font-medium">
                                {schedule.today_work_type}
                                {schedule.today_work_type !==
                                    schedule.work_type_label && (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        (usually {schedule.work_type_label})
                                    </span>
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Waiting on HR
                            </dt>
                            <dd className="mt-0.5 font-medium tabular-nums">
                                {schedule.pending === 0
                                    ? 'Nothing'
                                    : `${schedule.pending} request${schedule.pending === 1 ? '' : 's'}`}
                            </dd>
                        </div>
                    </dl>
                </Section>
            )}

            {can_manage && (
                <Section title={`Waiting on HR (${queue.length})`}>
                    {queue.length === 0 ? (
                        <EmptyNote>Nothing to review. All caught up.</EmptyNote>
                    ) : (
                        <ul className="divide-y divide-border">
                            {queue.map((request) => (
                                <li
                                    key={`${request.type}-${request.id}`}
                                    className="flex flex-wrap items-start gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <PersonAvatar
                                        person={request.employee}
                                        className="size-8"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">
                                            {request.employee.name}
                                            <span className="font-normal text-muted-foreground">
                                                {' '}
                                                · {request.kind}
                                            </span>
                                        </p>
                                        <p className="text-sm">
                                            {request.summary}
                                        </p>
                                        {request.reason && (
                                            <p className="mt-0.5 text-xs whitespace-pre-line text-muted-foreground">
                                                “{request.reason}”
                                            </p>
                                        )}
                                    </div>
                                    {request.can.review && (
                                        <div className="flex gap-1.5">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setDialog({
                                                        kind: 'review',
                                                        request,
                                                        decision: 'reject',
                                                    })
                                                }
                                            >
                                                <X aria-hidden="true" />
                                                Reject
                                            </Button>
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setDialog({
                                                        kind: 'review',
                                                        request,
                                                        decision: 'approve',
                                                    })
                                                }
                                            >
                                                <Check aria-hidden="true" />
                                                Approve
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            )}

            {schedule && (
                <Section title="My requests">
                    {my_requests.length === 0 ? (
                        <EmptyNote>
                            You have not asked for anything yet.
                        </EmptyNote>
                    ) : (
                        <ul className="divide-y divide-border">
                            {my_requests.map((request) => (
                                <li
                                    key={`${request.type}-${request.id}`}
                                    className="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium first-letter:uppercase">
                                            {request.kind}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {request.summary}
                                            {request.created_at &&
                                                ` · asked ${formatDate(request.created_at.slice(0, 10), { month: 'short', day: 'numeric' })}`}
                                        </p>
                                        {request.review_note && (
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {request.reviewer}: “
                                                {request.review_note}”
                                            </p>
                                        )}
                                    </div>
                                    <StatusPill
                                        status={request.status}
                                        label={request.status_label}
                                    />
                                    {request.can.cancel && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    requestUrl(
                                                        workspace.slug,
                                                        request,
                                                        'cancel',
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Withdraw
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            )}

            {!schedule && !can_manage && (
                <EmptyNote>
                    You do not have an employee record in this workspace, so
                    there is nothing to request.
                </EmptyNote>
            )}

            {dialog?.kind === 'shift' && (
                <ScheduleDialog type="shift" shifts={shifts} onClose={close} />
            )}
            {dialog?.kind === 'work_type' && (
                <ScheduleDialog
                    type="work_type"
                    workTypes={work_types.filter(
                        (option) => option.value !== schedule?.work_type,
                    )}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'attendance' && (
                <AttendanceDialog entries={recent_entries} onClose={close} />
            )}
            {dialog?.kind === 'review' && (
                <ReviewDialog
                    request={dialog.request}
                    decision={dialog.decision}
                    onClose={close}
                />
            )}
        </HrPage>
    );
}

/**
 * A different shift or work type, for good or for a stretch of days.
 */
function ScheduleDialog({
    type,
    shifts = [],
    workTypes = [],
    onClose,
}: {
    type: Extract<HrRequestType, 'shift' | 'work_type'>;
    shifts?: Shift[];
    workTypes?: Option[];
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const [permanent, setPermanent] = useState(true);
    const form = useForm({
        shift_id: '',
        work_type: '',
        starts_on: todayString(),
        ends_on: '',
        reason: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...(type === 'shift'
                ? { shift_id: Number(data.shift_id) }
                : { work_type: data.work_type }),
            starts_on: data.starts_on,
            ends_on: permanent || data.ends_on === '' ? null : data.ends_on,
            reason: data.reason,
        }));
        form.post(ROUTES[type].store(workspace.slug).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title={type === 'shift' ? 'Change shift' : 'Change work type'}
            description="HR will be notified and decide it."
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Send request"
        >
            {type === 'shift' ? (
                <SelectField
                    id="request-shift"
                    label="Shift"
                    value={form.data.shift_id}
                    onChange={(value) => form.setData('shift_id', value)}
                    options={shifts.map((shift) => ({
                        value: String(shift.id),
                        label: `${shift.name} (${shift.hours}, ${formatWeekdays(shift.days)})`,
                    }))}
                    error={form.errors.shift_id}
                />
            ) : (
                <SelectField
                    id="request-work-type"
                    label="Work type"
                    value={form.data.work_type}
                    onChange={(value) => form.setData('work_type', value)}
                    options={workTypes}
                    error={form.errors.work_type}
                />
            )}
            <label className="flex items-center gap-2 text-sm">
                <Checkbox
                    checked={permanent}
                    onCheckedChange={(checked) =>
                        setPermanent(checked === true)
                    }
                />
                Permanent change
            </label>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    label={permanent ? 'Starting' : 'From'}
                    htmlFor="request-starts"
                    error={form.errors.starts_on}
                >
                    <Input
                        id="request-starts"
                        type="date"
                        min={todayString()}
                        value={form.data.starts_on}
                        onChange={(event) =>
                            form.setData('starts_on', event.target.value)
                        }
                        required
                    />
                </Field>
                {!permanent && (
                    <Field
                        label="Until"
                        htmlFor="request-ends"
                        error={form.errors.ends_on}
                    >
                        <Input
                            id="request-ends"
                            type="date"
                            min={form.data.starts_on}
                            value={form.data.ends_on}
                            onChange={(event) =>
                                form.setData('ends_on', event.target.value)
                            }
                            required
                        />
                    </Field>
                )}
            </div>
            <Field
                label="Reason"
                htmlFor="request-reason"
                error={form.errors.reason}
            >
                <Textarea
                    id="request-reason"
                    rows={3}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    placeholder="Optional"
                />
            </Field>
        </FormDialog>
    );
}

/**
 * Correct one of your recent shifts, or add one you never clocked.
 */
function AttendanceDialog({
    entries,
    onClose,
}: {
    entries: TimeEntry[];
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        time_entry_id: '',
        clock_in_at: '',
        clock_out_at: '',
        reason: '',
    });

    function pickEntry(value: string) {
        const entry = entries.find(
            (candidate) => String(candidate.id) === value,
        );

        form.setData({
            ...form.data,
            time_entry_id: value,
            clock_in_at: entry ? toLocalInput(entry.clock_in_at) : '',
            clock_out_at: entry?.clock_out_at
                ? toLocalInput(entry.clock_out_at)
                : '',
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            time_entry_id:
                data.time_entry_id === '' ? null : Number(data.time_entry_id),
            clock_in_at: new Date(data.clock_in_at).toISOString(),
            clock_out_at: new Date(data.clock_out_at).toISOString(),
            reason: data.reason,
        }));
        form.post(attendanceRoutes.store(workspace.slug).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="Fix attendance"
            description="Correct a shift you clocked wrong, or add one you forgot to clock."
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
                options={entries.map((entry) => ({
                    value: String(entry.id),
                    label: `${formatDate(entry.clock_in_at.slice(0, 10), { weekday: 'short', month: 'short', day: 'numeric' })}, ${formatTime(entry.clock_in_at)} – ${formatTime(entry.clock_out_at)}`,
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

function ReviewDialog({
    request,
    decision,
    onClose,
}: {
    request: HrRequest;
    decision: 'approve' | 'reject';
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({ note: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(requestUrl(workspace.slug, request, decision), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title={`${decision === 'approve' ? 'Approve' : 'Reject'} ${request.kind}`}
            description={`${request.employee.name} · ${request.summary}`}
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel={decision === 'approve' ? 'Approve' : 'Reject'}
        >
            <Field
                label="Note to the employee"
                htmlFor="request-review-note"
                error={form.errors.note}
            >
                <Textarea
                    id="request-review-note"
                    rows={3}
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                    placeholder="Optional"
                />
            </Field>
        </FormDialog>
    );
}
