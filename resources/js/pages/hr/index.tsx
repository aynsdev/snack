import { Link } from '@inertiajs/react';
import {
    CalendarDays,
    Clock,
    Megaphone,
    Pin,
    Plane,
    Users,
} from 'lucide-react';
import { ClockCard } from '@/components/hr/clock-card';
import {
    EmptyNote,
    formatDate,
    formatDateRange,
    formatDays,
    HrPage,
    LEAVE_COLORS,
    PersonAvatar,
    Section,
    StatusPill,
} from '@/components/hr/hr-ui';
import { Button } from '@/components/ui/button';
import { useWorkspace } from '@/hooks/use-workspace';
import { cn } from '@/lib/utils';
import { index as announcementsIndex } from '@/routes/hr/announcements';
import { index as employeesIndex } from '@/routes/hr/employees';
import { index as holidaysIndex } from '@/routes/hr/holidays';
import { index as leaveIndex } from '@/routes/hr/leave';
import { index as timeIndex } from '@/routes/hr/time';
import type {
    Announcement,
    Geofence,
    LeaveBalance,
    LeaveRequest,
    TimeEntry,
} from '@/types/hr';

type Props = {
    employee: {
        id: number;
        job_title: string | null;
        department: string | null;
        status_label: string;
    } | null;
    clock: { open_entry: TimeEntry | null; today_minutes: number } | null;
    geofence: Geofence | null;
    require_location: boolean;
    balances: LeaveBalance[];
    my_requests: LeaveRequest[];
    announcements: Announcement[];
    upcoming_holidays: { id: number; name: string; date: string }[];
    out_today: {
        id: number;
        name: string;
        initials: string;
        avatar_url: string | null;
        leave_type: string;
        ends_on: string;
    }[];
    stats: {
        headcount: number;
        pending_leave: number;
        clocked_in: number;
    } | null;
    can_manage: boolean;
};

/**
 * HR home: the things a person checks every day, on one screen.
 */
export default function HrOverview({
    employee,
    clock,
    geofence,
    require_location,
    balances,
    my_requests,
    announcements,
    upcoming_holidays,
    out_today,
    stats,
}: Props) {
    const workspace = useWorkspace();

    return (
        <HrPage
            title="HR"
            description={
                employee
                    ? [employee.job_title, employee.department]
                          .filter(Boolean)
                          .join(' · ') || 'Your time, leave, and company news.'
                    : 'Company news and holidays.'
            }
            actions={
                employee && (
                    <Button asChild>
                        <Link href={leaveIndex(workspace.slug)}>
                            <Plane aria-hidden="true" />
                            Request leave
                        </Link>
                    </Button>
                )
            }
        >
            {stats && (
                <div className="grid gap-3 sm:grid-cols-3">
                    <Stat
                        icon={Users}
                        label="Employees"
                        value={stats.headcount}
                        href={employeesIndex(workspace.slug).url}
                    />
                    <Stat
                        icon={Plane}
                        label="Leave awaiting review"
                        value={stats.pending_leave}
                        href={leaveIndex(workspace.slug).url}
                        highlight={stats.pending_leave > 0}
                    />
                    <Stat
                        icon={Clock}
                        label="Clocked in now"
                        value={stats.clocked_in}
                        href={
                            timeIndex(workspace.slug, {
                                query: { scope: 'team' },
                            }).url
                        }
                    />
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0 space-y-6">
                    {clock && (
                        <ClockCard
                            openEntry={clock.open_entry}
                            todayMinutes={clock.today_minutes}
                            geofence={geofence}
                            requireLocation={require_location}
                        />
                    )}

                    <Section
                        title="Announcements"
                        action={
                            <Link
                                href={announcementsIndex(workspace.slug)}
                                className="text-xs font-medium text-muted-foreground hover:text-foreground"
                            >
                                View all
                            </Link>
                        }
                    >
                        {announcements.length === 0 ? (
                            <EmptyNote>No announcements yet.</EmptyNote>
                        ) : (
                            <ul className="divide-y divide-border">
                                {announcements.map((announcement) => (
                                    <li
                                        key={announcement.id}
                                        className="py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="flex items-center gap-2">
                                            {announcement.is_pinned && (
                                                <Pin
                                                    aria-label="Pinned"
                                                    className="size-3.5 text-primary"
                                                />
                                            )}
                                            <h3 className="truncate text-sm font-semibold">
                                                {announcement.title}
                                            </h3>
                                        </div>
                                        <p className="mt-1 line-clamp-3 text-sm whitespace-pre-line text-muted-foreground">
                                            {announcement.body}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {announcement.author?.name ??
                                                'Someone'}{' '}
                                            ·{' '}
                                            {announcement.created_at &&
                                                new Date(
                                                    announcement.created_at,
                                                ).toLocaleDateString()}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    {employee && (
                        <Section
                            title="My leave requests"
                            action={
                                <Link
                                    href={leaveIndex(workspace.slug)}
                                    className="text-xs font-medium text-muted-foreground hover:text-foreground"
                                >
                                    View all
                                </Link>
                            }
                        >
                            {my_requests.length === 0 ? (
                                <EmptyNote>
                                    You have not requested any leave.
                                </EmptyNote>
                            ) : (
                                <ul className="divide-y divide-border">
                                    {my_requests.map((request) => (
                                        <li
                                            key={request.id}
                                            className="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {request.leave_type.name} ·{' '}
                                                    {formatDays(request.days)}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDateRange(
                                                        request.starts_on,
                                                        request.ends_on,
                                                    )}
                                                </p>
                                            </div>
                                            <StatusPill
                                                status={request.status}
                                                label={request.status_label}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    )}
                </div>

                <aside className="space-y-6">
                    {balances.length > 0 && (
                        <Section
                            title={`Leave balance ${new Date().getFullYear()}`}
                        >
                            <ul className="space-y-3">
                                {balances.map((balance) => (
                                    <li key={balance.leave_type_id}>
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="flex items-center gap-2">
                                                <span
                                                    aria-hidden="true"
                                                    className={cn(
                                                        'size-2 rounded-full',
                                                        LEAVE_COLORS[
                                                            balance.color
                                                        ] ?? 'bg-slate-500',
                                                    )}
                                                />
                                                {balance.name}
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                {balance.remaining === null
                                                    ? `${balance.used} used`
                                                    : `${balance.remaining} of ${balance.allowance} left`}
                                            </span>
                                        </div>
                                        {balance.allowance !== null &&
                                            balance.allowance > 0 && (
                                                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className={cn(
                                                            'h-full rounded-full',
                                                            LEAVE_COLORS[
                                                                balance.color
                                                            ] ?? 'bg-slate-500',
                                                        )}
                                                        style={{
                                                            width: `${Math.min(100, (balance.used / balance.allowance) * 100)}%`,
                                                        }}
                                                    />
                                                </div>
                                            )}
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    )}

                    <Section title="Out today">
                        {out_today.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Everyone is in.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {out_today.map((person) => (
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
                                                {person.leave_type} · back after{' '}
                                                {formatDate(person.ends_on, {
                                                    month: 'short',
                                                    day: 'numeric',
                                                })}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section
                        title="Upcoming holidays"
                        action={
                            <Link
                                href={holidaysIndex(workspace.slug)}
                                className="text-xs font-medium text-muted-foreground hover:text-foreground"
                            >
                                All
                            </Link>
                        }
                    >
                        {upcoming_holidays.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                None scheduled.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {upcoming_holidays.map((holiday) => (
                                    <li
                                        key={holiday.id}
                                        className="flex items-center gap-3"
                                    >
                                        <CalendarDays
                                            aria-hidden="true"
                                            className="size-4 shrink-0 text-muted-foreground"
                                        />
                                        <span className="min-w-0 flex-1 truncate text-sm">
                                            {holiday.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {formatDate(holiday.date, {
                                                month: 'short',
                                                day: 'numeric',
                                                weekday: 'short',
                                            })}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    {!employee && (
                        <p className="flex items-start gap-2 text-xs text-muted-foreground">
                            <Megaphone
                                aria-hidden="true"
                                className="size-4 shrink-0"
                            />
                            You do not have an employee record in this
                            workspace, so the clock and leave are unavailable.
                        </p>
                    )}
                </aside>
            </div>
        </HrPage>
    );
}

function Stat({
    icon: Icon,
    label,
    value,
    href,
    highlight = false,
}: {
    icon: typeof Users;
    label: string;
    value: number;
    href: string;
    highlight?: boolean;
}) {
    return (
        <Link
            href={href}
            className={cn(
                'flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:bg-accent',
                highlight && 'border-amber-500/40',
            )}
        >
            <span className="flex size-9 items-center justify-center rounded-lg bg-muted">
                <Icon aria-hidden="true" className="size-4" />
            </span>
            <span>
                <span className="block text-xl font-bold tabular-nums">
                    {value}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {label}
                </span>
            </span>
        </Link>
    );
}
