import { router, useForm } from '@inertiajs/react';
import { Check, Plane, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { FormDialog } from '@/components/hr/hr-dialogs';
import {
    EmptyNote,
    Field,
    formatDate,
    formatDateRange,
    formatDays,
    HrPage,
    LEAVE_COLORS,
    parseDate,
    PersonAvatar,
    Section,
    SelectField,
    StatusPill,
    todayString,
} from '@/components/hr/hr-ui';
import { LeaveReviewDialog } from '@/components/hr/leave-review-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import {
    cancel as cancelLeave,
    index as leaveIndex,
    store as storeLeave,
} from '@/routes/hr/leave';
import type { LeaveBalance, LeaveRequest, LeaveType, Option } from '@/types/hr';

type Props = {
    leave_types: LeaveType[];
    balances: LeaveBalance[];
    my_requests: LeaveRequest[];
    queue: LeaveRequest[];
    filters: { status: string };
    statuses: Option[];
    holidays: { name: string; date: string }[];
    can_request: boolean;
    can_manage: boolean;
};

/**
 * Asking for time off, and for HR, deciding on it.
 */
export default function LeavePage({
    leave_types,
    balances,
    my_requests,
    queue,
    filters,
    statuses,
    holidays,
    can_request,
    can_manage,
}: Props) {
    const workspace = useWorkspace();
    const [requesting, setRequesting] = useState(false);
    const [reviewing, setReviewing] = useState<{
        request: LeaveRequest;
        decision: 'approve' | 'reject';
    } | null>(null);

    return (
        <HrPage
            title="Leave"
            description="Request time off. Days are counted in working days — weekends and company holidays are not deducted."
            actions={
                can_request && (
                    <Button onClick={() => setRequesting(true)}>
                        <Plane aria-hidden="true" />
                        Request leave
                    </Button>
                )
            }
        >
            {balances.length > 0 && (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {balances.map((balance) => (
                        <div
                            key={balance.leave_type_id}
                            className="rounded-xl border border-border bg-card p-3"
                        >
                            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'size-2 rounded-full',
                                        LEAVE_COLORS[balance.color] ??
                                            'bg-slate-500',
                                    )}
                                />
                                {balance.name}
                            </p>
                            <p className="mt-1 text-lg font-bold tabular-nums">
                                {balance.remaining === null
                                    ? `${balance.used} used`
                                    : `${balance.remaining} left`}
                            </p>
                            {balance.allowance !== null && (
                                <p className="text-xs text-muted-foreground">
                                    of {balance.allowance} in{' '}
                                    {new Date().getFullYear()}
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {can_manage && (
                <Section
                    title="Requests to review"
                    action={
                        <div
                            className="flex flex-wrap gap-1"
                            role="group"
                            aria-label="Filter by status"
                        >
                            {statuses.map((status) => (
                                <Button
                                    key={status.value}
                                    size="sm"
                                    variant={
                                        filters.status === status.value
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    onClick={() =>
                                        router.get(
                                            leaveIndex(workspace.slug).url,
                                            { status: status.value },
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                                replace: true,
                                            },
                                        )
                                    }
                                >
                                    {status.label}
                                </Button>
                            ))}
                        </div>
                    }
                >
                    {queue.length === 0 ? (
                        <EmptyNote>
                            {filters.status === 'pending'
                                ? 'Nothing waiting on you.'
                                : 'No requests.'}
                        </EmptyNote>
                    ) : (
                        <ul className="divide-y divide-border">
                            {queue.map((request) => (
                                <li
                                    key={request.id}
                                    className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <PersonAvatar person={request.employee} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">
                                            {request.employee.name}
                                            <span className="ml-2 font-normal text-muted-foreground">
                                                {request.leave_type.name} ·{' '}
                                                {formatDays(request.days)}
                                            </span>
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {formatDateRange(
                                                request.starts_on,
                                                request.ends_on,
                                            )}
                                            {request.reason &&
                                                ` · “${request.reason}”`}
                                        </p>
                                        {request.reviewer && (
                                            <p className="text-xs text-muted-foreground">
                                                {request.status_label} by{' '}
                                                {request.reviewer}
                                                {request.review_note &&
                                                    ` — ${request.review_note}`}
                                            </p>
                                        )}
                                    </div>
                                    {request.can.review ? (
                                        <div className="flex gap-1">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setReviewing({
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
                                                    setReviewing({
                                                        request,
                                                        decision: 'approve',
                                                    })
                                                }
                                            >
                                                <Check aria-hidden="true" />
                                                Approve
                                            </Button>
                                        </div>
                                    ) : (
                                        <StatusPill
                                            status={request.status}
                                            label={request.status_label}
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            )}

            <Section title="My requests">
                {!can_request ? (
                    <EmptyNote>
                        You do not have an employee record in this workspace.
                    </EmptyNote>
                ) : my_requests.length === 0 ? (
                    <EmptyNote>You have not requested any leave yet.</EmptyNote>
                ) : (
                    <ul className="divide-y divide-border">
                        {my_requests.map((request) => (
                            <li
                                key={request.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {request.leave_type.name} ·{' '}
                                        {formatDays(request.days)}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDateRange(
                                            request.starts_on,
                                            request.ends_on,
                                        )}
                                        {request.reason &&
                                            ` · ${request.reason}`}
                                    </p>
                                    {request.review_note && (
                                        <p className="text-xs text-muted-foreground">
                                            {request.reviewer}:{' '}
                                            {request.review_note}
                                        </p>
                                    )}
                                </div>
                                <div className="flex items-center gap-2">
                                    <StatusPill
                                        status={request.status}
                                        label={request.status_label}
                                    />
                                    {request.can.cancel && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        'Withdraw this leave request?',
                                                    )
                                                ) {
                                                    router.post(
                                                        cancelLeave({
                                                            workspace:
                                                                workspace.slug,
                                                            leaveRequest:
                                                                request.id,
                                                        }).url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            Withdraw
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {requesting && (
                <RequestDialog
                    leaveTypes={leave_types}
                    balances={balances}
                    holidays={holidays}
                    onClose={() => setRequesting(false)}
                />
            )}

            {reviewing && (
                <LeaveReviewDialog
                    key={`${reviewing.request.id}-${reviewing.decision}`}
                    request={reviewing.request}
                    decision={reviewing.decision}
                    onClose={() => setReviewing(null)}
                />
            )}
        </HrPage>
    );
}

/**
 * Working days between two dates, excluding weekends and the given holidays.
 * An estimate for the form — the server counts again and its figure is final.
 */
function estimateWorkingDays(
    from: string,
    to: string,
    holidays: Set<string>,
): number {
    if (!from || !to || to < from) {
        return 0;
    }

    let days = 0;
    const cursor = parseDate(from);
    const end = parseDate(to);

    while (cursor <= end) {
        const iso = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`;
        const weekday = cursor.getDay();

        if (weekday !== 0 && weekday !== 6 && !holidays.has(iso)) {
            days++;
        }

        cursor.setDate(cursor.getDate() + 1);
    }

    return days;
}

function RequestDialog({
    leaveTypes,
    balances,
    holidays,
    onClose,
}: {
    leaveTypes: LeaveType[];
    balances: LeaveBalance[];
    holidays: { name: string; date: string }[];
    onClose: () => void;
}) {
    const workspace = useWorkspace();
    const form = useForm({
        leave_type_id: leaveTypes[0] ? String(leaveTypes[0].id) : '',
        starts_on: todayString(),
        ends_on: todayString(),
        reason: '',
    });

    const holidayDates = useMemo(
        () => new Set(holidays.map((holiday) => holiday.date)),
        [holidays],
    );
    const days = estimateWorkingDays(
        form.data.starts_on,
        form.data.ends_on,
        holidayDates,
    );
    const balance = balances.find(
        (item) => String(item.leave_type_id) === form.data.leave_type_id,
    );
    const holidaysInRange = holidays.filter(
        (holiday) =>
            holiday.date >= form.data.starts_on &&
            holiday.date <= form.data.ends_on,
    );

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            leave_type_id: Number(data.leave_type_id),
        }));
        form.post(storeLeave(workspace.slug).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <FormDialog
            title="Request leave"
            onClose={onClose}
            onSubmit={submit}
            processing={form.processing}
            submitLabel="Send request"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    id="leave-type"
                    label="Type"
                    value={form.data.leave_type_id}
                    onChange={(value) => form.setData('leave_type_id', value)}
                    options={leaveTypes.map((type) => ({
                        value: String(type.id),
                        label: type.is_paid
                            ? type.name
                            : `${type.name} (unpaid)`,
                    }))}
                    error={form.errors.leave_type_id}
                    className="sm:col-span-2"
                />
                <Field
                    label="From"
                    htmlFor="leave-from"
                    error={form.errors.starts_on}
                >
                    <Input
                        id="leave-from"
                        type="date"
                        value={form.data.starts_on}
                        onChange={(event) => {
                            const value = event.target.value;
                            form.setData((data) => ({
                                ...data,
                                starts_on: value,
                                ends_on:
                                    data.ends_on < value ? value : data.ends_on,
                            }));
                        }}
                        required
                    />
                </Field>
                <Field
                    label="To"
                    htmlFor="leave-to"
                    error={form.errors.ends_on}
                >
                    <Input
                        id="leave-to"
                        type="date"
                        min={form.data.starts_on}
                        value={form.data.ends_on}
                        onChange={(event) =>
                            form.setData('ends_on', event.target.value)
                        }
                        required
                    />
                </Field>
                <Field
                    label="Reason"
                    htmlFor="leave-reason"
                    error={form.errors.reason}
                    className="sm:col-span-2"
                >
                    <Textarea
                        id="leave-reason"
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        rows={3}
                        placeholder="Optional"
                    />
                </Field>
            </div>

            <div className="rounded-lg bg-muted px-3 py-2 text-sm">
                <p>
                    <span className="font-semibold">{formatDays(days)}</span> of
                    leave
                    {balance && balance.remaining !== null && (
                        <span
                            className={cn(
                                'text-muted-foreground',
                                days > balance.remaining && 'text-destructive',
                            )}
                        >
                            {' '}
                            · {
                                balance.remaining
                            } {balance.name.toLowerCase()} left
                        </span>
                    )}
                </p>
                {holidaysInRange.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                        Not counted:{' '}
                        {holidaysInRange
                            .map(
                                (holiday) =>
                                    `${holiday.name} (${formatDate(holiday.date, { month: 'short', day: 'numeric' })})`,
                            )
                            .join(', ')}
                    </p>
                )}
            </div>
        </FormDialog>
    );
}
