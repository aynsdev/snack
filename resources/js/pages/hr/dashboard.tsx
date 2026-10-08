import { Link } from '@inertiajs/react';
import {
    Cake,
    Check,
    Clock,
    FileClock,
    Inbox,
    Plane,
    UserMinus,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';
import {
    EmptyNote,
    formatDate,
    formatDateRange,
    formatDays,
    formatMinutes,
    HrPage,
    PersonAvatar,
    Section,
} from '@/components/hr/hr-ui';
import { LeaveReviewDialog } from '@/components/hr/leave-review-dialog';
import {
    BarRows,
    Panel,
    rampStep,
    StackedBar,
    StatTile,
} from '@/components/snack/projects/summary-charts';
import { Button } from '@/components/ui/button';
import { useWorkspace } from '@/hooks/use-workspace';
import { show as showEmployee } from '@/routes/hr/employees';
import { index as leaveIndex } from '@/routes/hr/leave';
import { index as requestsIndex } from '@/routes/hr/requests';
import { index as timeIndex } from '@/routes/hr/time';
import type { LeaveRequest } from '@/types/hr';

type Count = { key: string | number; label: string; count: number };

type Person = {
    name: string;
    initials: string;
    avatar_url: string | null;
};

type Props = {
    kpis: {
        headcount: number;
        new_hires: number;
        leavers: number;
        on_leave_today: number;
        clocked_in: number;
        pending_approvals: number;
    };
    departments: Count[];
    employment_types: Count[];
    movement: { month: string; hires: number; leavers: number }[];
    attendance: {
        clocked_in_today: number;
        on_leave: number;
        not_in: number;
        late_today: number;
        week_minutes: number;
    };
    leave_by_type: Count[];
    out_this_week: (Person & {
        id: number;
        leave_type: string;
        starts_on: string;
        ends_on: string;
    })[];
    celebrations: (Person & {
        employee_id: number;
        kind: 'birthday' | 'anniversary';
        date: string;
        years: number | null;
    })[];
    expiring_contracts: {
        id: number;
        employee_id: number;
        name: string;
        title: string;
        ends_on: string;
    }[];
    pending_leave: LeaveRequest[];
    pending_requests: { shift: number; work_type: number; attendance: number };
    late_arrivals: (Person & {
        employee_id: number;
        times: number;
        minutes: number;
    })[];
};

/**
 * HR's own dashboard: the workforce at a glance and what is waiting on HR.
 *
 * Admin only — the HR home stays every employee's self-service page. Every
 * figure is computed server-side in App\Services\Hr\HrDashboardStats.
 */
export default function HrDashboard({
    kpis,
    departments,
    employment_types,
    movement,
    attendance,
    leave_by_type,
    out_this_week,
    celebrations,
    expiring_contracts,
    pending_leave,
    pending_requests,
    late_arrivals,
}: Props) {
    const workspace = useWorkspace();
    const [reviewing, setReviewing] = useState<{
        request: LeaveRequest;
        decision: 'approve' | 'reject';
    } | null>(null);

    const otherPending =
        pending_requests.shift +
        pending_requests.work_type +
        pending_requests.attendance;
    const departmentTotal = departments.reduce(
        (sum, row) => sum + row.count,
        0,
    );
    const leaveTotal = leave_by_type.reduce((sum, row) => sum + row.count, 0);

    return (
        <HrPage
            title="HR dashboard"
            description="Your workforce at a glance, and everything waiting on HR."
            width="max-w-6xl"
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <StatTile
                    icon={Users}
                    value={kpis.headcount}
                    label="employed"
                    hint="Everyone not terminated"
                />
                <StatTile
                    icon={UserPlus}
                    value={kpis.new_hires}
                    label="joined"
                    hint="Hired this month"
                />
                <StatTile
                    icon={UserMinus}
                    value={kpis.leavers}
                    label="left"
                    hint="Terminated this month"
                />
                <StatTile
                    icon={Plane}
                    value={kpis.on_leave_today}
                    label="on leave"
                    hint="Approved leave today"
                />
                <StatTile
                    icon={Clock}
                    value={kpis.clocked_in}
                    label="clocked in"
                    hint="On the clock right now"
                />
                <StatTile
                    icon={Inbox}
                    value={kpis.pending_approvals}
                    label="to review"
                    hint="Requests waiting on HR"
                />
            </div>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="min-w-0 space-y-6">
                    <Section
                        title={`Waiting on HR (${kpis.pending_approvals})`}
                        action={
                            <Link
                                href={leaveIndex(workspace.slug)}
                                className="text-xs font-medium text-muted-foreground hover:text-foreground"
                            >
                                All leave
                            </Link>
                        }
                    >
                        {pending_leave.length === 0 ? (
                            <EmptyNote>
                                {otherPending === 0
                                    ? 'Nothing to review. All caught up.'
                                    : 'No leave waiting.'}
                            </EmptyNote>
                        ) : (
                            <ul className="divide-y divide-border">
                                {pending_leave.map((request) => (
                                    <li
                                        key={request.id}
                                        className="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                                    >
                                        <PersonAvatar
                                            person={request.employee}
                                            className="size-8"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {request.employee.name}
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    · {request.leave_type.name}
                                                </span>
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {formatDateRange(
                                                    request.starts_on,
                                                    request.ends_on,
                                                )}{' '}
                                                · {formatDays(request.days)}
                                            </p>
                                        </div>
                                        {request.can.review && (
                                            <div className="flex gap-1.5">
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
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {otherPending > 0 && (
                            <Link
                                href={requestsIndex(workspace.slug)}
                                className="mt-3 flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2 text-sm hover:bg-accent"
                            >
                                <span>
                                    {[
                                        [
                                            pending_requests.shift,
                                            'shift change',
                                        ],
                                        [
                                            pending_requests.work_type,
                                            'work type change',
                                        ],
                                        [
                                            pending_requests.attendance,
                                            'attendance correction',
                                        ],
                                    ]
                                        .filter(([count]) => Number(count) > 0)
                                        .map(
                                            ([count, label]) =>
                                                `${count} ${label}${count === 1 ? '' : 's'}`,
                                        )
                                        .join(' · ')}
                                </span>
                                <span className="text-xs font-medium text-muted-foreground">
                                    Review
                                </span>
                            </Link>
                        )}
                    </Section>

                    <div className="grid gap-6 md:grid-cols-2">
                        <Panel
                            title="By department"
                            description="Current headcount per department."
                        >
                            <BarRows
                                rows={departments}
                                total={departmentTotal}
                                emptyLabel="Nobody is employed yet."
                            />
                        </Panel>

                        <Panel
                            title="By employment type"
                            description="How the current headcount is employed."
                        >
                            <StackedBar
                                segments={employment_types}
                                total={kpis.headcount}
                            />
                        </Panel>
                    </div>

                    <Panel
                        title="Joiners and leavers"
                        description={`Hires and terminations over the last ${movement.length} months.`}
                    >
                        <MovementChart movement={movement} />
                    </Panel>

                    <div className="grid gap-6 md:grid-cols-2">
                        <Panel
                            title="Attendance today"
                            description={`${attendance.late_today} late today · ${formatMinutes(attendance.week_minutes)} clocked this week.`}
                        >
                            <StackedBar
                                segments={[
                                    {
                                        key: 'in',
                                        label: 'Clocked in',
                                        count: attendance.clocked_in_today,
                                    },
                                    {
                                        key: 'leave',
                                        label: 'On leave',
                                        count: attendance.on_leave,
                                    },
                                    {
                                        key: 'not-in',
                                        label: 'Not in yet',
                                        count: attendance.not_in,
                                    },
                                ]}
                                total={
                                    attendance.clocked_in_today +
                                    attendance.on_leave +
                                    attendance.not_in
                                }
                            />
                            <Link
                                href={
                                    timeIndex(workspace.slug, {
                                        query: { scope: 'team' },
                                    }).url
                                }
                                className="mt-3 inline-block text-xs font-medium text-muted-foreground hover:text-foreground"
                            >
                                Open timesheets
                            </Link>
                        </Panel>

                        <Panel
                            title={`Leave taken in ${new Date().getFullYear()}`}
                            description="Approved days per leave type."
                        >
                            <BarRows
                                rows={leave_by_type}
                                total={leaveTotal}
                                emptyLabel="No approved leave this year."
                            />
                        </Panel>
                    </div>
                </div>

                <aside className="space-y-6">
                    <Section title="Out this week">
                        {out_this_week.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nobody is on leave in the next 7 days.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {out_this_week.map((person) => (
                                    <li
                                        key={person.id}
                                        className="flex items-center gap-2"
                                    >
                                        <PersonAvatar
                                            person={person}
                                            className="size-7"
                                        />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {person.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {person.leave_type} ·{' '}
                                                {formatDateRange(
                                                    person.starts_on,
                                                    person.ends_on,
                                                )}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section
                        title="Late this month"
                        action={
                            <Link
                                href={
                                    timeIndex(workspace.slug, {
                                        query: { scope: 'team', flag: 'late' },
                                    }).url
                                }
                                className="text-xs font-medium text-muted-foreground hover:text-foreground"
                            >
                                All
                            </Link>
                        }
                    >
                        {late_arrivals.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nobody has clocked in late this month.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {late_arrivals.map((person) => (
                                    <li
                                        key={person.employee_id}
                                        className="flex items-center gap-2"
                                    >
                                        <PersonAvatar
                                            person={person}
                                            className="size-7"
                                        />
                                        <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                            {person.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {person.times}× ·{' '}
                                            {formatMinutes(person.minutes)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title="Birthdays and anniversaries">
                        {celebrations.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing in the next 30 days.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {celebrations.map((event) => (
                                    <li
                                        key={`${event.kind}-${event.employee_id}`}
                                    >
                                        <Link
                                            href={showEmployee({
                                                workspace: workspace.slug,
                                                employee: event.employee_id,
                                            })}
                                            className="flex items-center gap-2 rounded-lg hover:bg-accent"
                                        >
                                            <PersonAvatar
                                                person={event}
                                                className="size-7"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {event.name}
                                                </p>
                                                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                                    <Cake
                                                        aria-hidden="true"
                                                        className="size-3"
                                                    />
                                                    {event.kind === 'birthday'
                                                        ? 'Birthday'
                                                        : `${event.years} year${event.years === 1 ? '' : 's'} at the company`}
                                                </p>
                                            </div>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {formatDate(event.date, {
                                                    month: 'short',
                                                    day: 'numeric',
                                                })}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title="Contracts ending soon">
                        {expiring_contracts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No contracts end in the next 30 days.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {expiring_contracts.map((contract) => (
                                    <li key={contract.id}>
                                        <Link
                                            href={showEmployee({
                                                workspace: workspace.slug,
                                                employee: contract.employee_id,
                                            })}
                                            className="flex items-center gap-2 rounded-lg hover:bg-accent"
                                        >
                                            <FileClock
                                                aria-hidden="true"
                                                className="size-4 shrink-0 text-muted-foreground"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {contract.name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {contract.title}
                                                </p>
                                            </div>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {formatDate(contract.ends_on, {
                                                    month: 'short',
                                                    day: 'numeric',
                                                })}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                </aside>
            </div>

            {reviewing && (
                <LeaveReviewDialog
                    request={reviewing.request}
                    decision={reviewing.decision}
                    onClose={() => setReviewing(null)}
                />
            )}
        </HrPage>
    );
}

/**
 * Paired columns per month: hires on the light end of the ramp, leavers on the
 * dark end. Scaled against the busiest month; every value is printed above
 * its column, so nothing is read off the height alone.
 */
function MovementChart({
    movement,
}: {
    movement: { month: string; hires: number; leavers: number }[];
}) {
    const largest = Math.max(
        1,
        ...movement.flatMap((month) => [month.hires, month.leavers]),
    );
    const series = [
        { key: 'hires' as const, label: 'Joined', color: rampStep(0.35) },
        { key: 'leavers' as const, label: 'Left', color: rampStep(1) },
    ];

    return (
        <div>
            <div
                role="img"
                aria-label={movement
                    .map(
                        (month) =>
                            `${monthLabel(month.month)}: ${month.hires} joined, ${month.leavers} left`,
                    )
                    .join('; ')}
                className="grid h-36 items-end gap-2"
                style={{
                    gridTemplateColumns: `repeat(${movement.length}, minmax(0, 1fr))`,
                }}
            >
                {movement.map((month) => (
                    <div
                        key={month.month}
                        className="flex h-full items-end justify-center gap-1"
                    >
                        {series.map((s) => (
                            <div
                                key={s.key}
                                className="flex h-full w-full max-w-6 flex-col items-center justify-end gap-1"
                            >
                                <span className="text-[0.65rem] text-muted-foreground tabular-nums">
                                    {month[s.key]}
                                </span>
                                <span
                                    title={`${s.label}: ${month[s.key]}`}
                                    className="block w-full rounded-t"
                                    style={{
                                        height: `${(month[s.key] / largest) * 100}%`,
                                        minHeight: month[s.key] > 0 ? 2 : 0,
                                        background: s.color,
                                    }}
                                />
                            </div>
                        ))}
                    </div>
                ))}
            </div>

            <div
                className="mt-2 grid gap-2 border-t border-border pt-2"
                style={{
                    gridTemplateColumns: `repeat(${movement.length}, minmax(0, 1fr))`,
                }}
            >
                {movement.map((month) => (
                    <span
                        key={month.month}
                        className="text-center text-xs text-muted-foreground"
                    >
                        {monthLabel(month.month)}
                    </span>
                ))}
            </div>

            <ul className="mt-3 flex gap-4">
                {series.map((s) => (
                    <li key={s.key} className="flex items-center gap-2 text-sm">
                        <span
                            aria-hidden="true"
                            style={{ background: s.color }}
                            className="size-2.5 rounded-[3px]"
                        />
                        <span className="text-muted-foreground">{s.label}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function monthLabel(month: string): string {
    const [year, monthIndex] = month.split('-').map(Number);

    return new Date(year, monthIndex - 1, 1).toLocaleDateString(undefined, {
        month: 'short',
    });
}
