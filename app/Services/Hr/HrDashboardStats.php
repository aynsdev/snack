<?php

namespace App\Services\Hr;

use App\Enums\ContractStatus;
use App\Enums\EmploymentType;
use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveRequest;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind HR's own dashboard: who works here, who is in today, what
 * is waiting on HR, and what is coming up.
 *
 * Every figure is for one workspace and counts only people still employed
 * unless it is explicitly about leavers.
 */
final class HrDashboardStats
{
    /** How far ahead birthdays, anniversaries, and expiring contracts are shown. */
    public const UPCOMING_DAYS = 30;

    /** How many months the joiners-and-leavers chart covers, this one included. */
    public const MOVEMENT_MONTHS = 6;

    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @return array{headcount: int, new_hires: int, leavers: int, on_leave_today: int, clocked_in: int, pending_approvals: int}
     */
    public function kpis(): array
    {
        $monthStart = today()->startOfMonth();

        return [
            'headcount' => $this->workspace->employees()->employed()->count(),
            'new_hires' => $this->workspace->employees()->employed()->whereDate('hired_on', '>=', $monthStart)->count(),
            'leavers' => $this->workspace->employees()->whereDate('terminated_on', '>=', $monthStart)->count(),
            'on_leave_today' => $this->onLeaveToday(),
            'clocked_in' => $this->workspace->timeEntries()->whereNull('clock_out_at')->count(),
            'pending_approvals' => $this->pendingApprovals(),
        ];
    }

    /**
     * Everything waiting on an HR decision. Each module with an approval queue
     * adds its count here.
     */
    public function pendingApprovals(): int
    {
        return $this->workspace->leaveRequests()->where('status', LeaveStatus::Pending)->count()
            + array_sum($this->pendingRequests());
    }

    /**
     * Shift, work-type, and attendance requests, and uploaded documents,
     * waiting on HR, by kind.
     *
     * @return array{shift: int, work_type: int, attendance: int, documents: int}
     */
    public function pendingRequests(): array
    {
        return [
            'shift' => $this->workspace->shiftRequests()->pending()->count(),
            'work_type' => $this->workspace->workTypeRequests()->pending()->count(),
            'attendance' => $this->workspace->attendanceRequests()->pending()->count(),
            'documents' => $this->workspace->documentSubmissions()->awaitingReview()->count(),
        ];
    }

    /**
     * Who clocked in late most often this month, worst first.
     *
     * @return array<int, array{employee_id: int, name: string, initials: string, avatar_url: string|null, times: int, minutes: int}>
     */
    public function lateArrivals(int $limit = 5): array
    {
        $rows = $this->workspace->timeEntries()
            ->where('clock_in_at', '>=', today()->startOfMonth())
            ->where('late_minutes', '>', 0)
            ->toBase()
            ->select('employee_id', DB::raw('count(*) as times'), DB::raw('sum(late_minutes) as minutes'))
            ->groupBy('employee_id')
            ->orderByDesc('times')
            ->orderByDesc('minutes')
            ->limit($limit)
            ->get();

        $employees = $this->workspace->employees()->with('user')->findMany($rows->pluck('employee_id'))->keyBy('id');

        return $rows
            ->filter(fn ($row): bool => $employees->has($row->employee_id))
            ->map(function ($row) use ($employees): array {
                $user = $employees[$row->employee_id]->user;

                return [
                    'employee_id' => (int) $row->employee_id,
                    'name' => $user->name,
                    'initials' => $user->initials(),
                    'avatar_url' => $user->avatarUrl(),
                    'times' => (int) $row->times,
                    'minutes' => (int) $row->minutes,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Current headcount per department, largest first, with the people who
     * have none grouped at the end.
     *
     * @return array<int, array{key: string, label: string, count: int}>
     */
    public function departments(): array
    {
        $counts = $this->workspace->employees()
            ->employed()
            ->toBase()
            ->select('department', DB::raw('count(*) as total'))
            ->groupBy('department')
            ->pluck('total', 'department');

        return $counts
            ->map(fn ($total, $department): array => [
                'key' => $department === '' ? '_none' : (string) $department,
                'label' => $department === '' ? 'Unassigned' : (string) $department,
                'count' => (int) $total,
            ])
            ->sortBy([
                fn (array $a, array $b): int => ($a['key'] === '_none') <=> ($b['key'] === '_none'),
                fn (array $a, array $b): int => $b['count'] <=> $a['count'],
            ])
            ->values()
            ->all();
    }

    /**
     * Current headcount per employment type, every type listed even at zero.
     *
     * @return array<int, array{key: string, label: string, count: int}>
     */
    public function employmentTypes(): array
    {
        $counts = $this->workspace->employees()
            ->employed()
            ->toBase()
            ->select('employment_type', DB::raw('count(*) as total'))
            ->groupBy('employment_type')
            ->pluck('total', 'employment_type');

        return array_map(fn (EmploymentType $type): array => [
            'key' => $type->value,
            'label' => $type->label(),
            'count' => (int) ($counts[$type->value] ?? 0),
        ], EmploymentType::cases());
    }

    /**
     * Joiners and leavers per month, oldest month first.
     *
     * @return array<int, array{month: string, hires: int, leavers: int}>
     */
    public function movement(): array
    {
        $from = CarbonImmutable::today()->startOfMonth()->subMonths(self::MOVEMENT_MONTHS - 1);

        $employees = $this->workspace->employees()
            ->where(fn ($query) => $query
                ->whereDate('hired_on', '>=', $from)
                ->orWhereDate('terminated_on', '>=', $from))
            ->get(['hired_on', 'terminated_on']);

        return collect(range(0, self::MOVEMENT_MONTHS - 1))
            ->map(function (int $offset) use ($from, $employees): array {
                $month = $from->addMonths($offset);

                return [
                    'month' => $month->format('Y-m'),
                    'hires' => $employees->filter(fn (Employee $employee): bool => $employee->hired_on?->isSameMonth($month) === true)->count(),
                    'leavers' => $employees->filter(fn (Employee $employee): bool => $employee->terminated_on?->isSameMonth($month) === true)->count(),
                ];
            })
            ->all();
    }

    /**
     * Today's attendance split, plus the hours clocked so far this week.
     *
     * "Not in" is everybody employed who has neither clocked in today nor is on
     * approved leave — it is not an absence, just nobody has seen them yet.
     *
     * @return array{clocked_in_today: int, on_leave: int, not_in: int, late_today: int, week_minutes: int}
     */
    public function attendance(): array
    {
        $headcount = $this->workspace->employees()->employed()->count();

        $clockedInToday = $this->workspace->timeEntries()
            ->where('clock_in_at', '>=', today())
            ->distinct()
            ->count('employee_id');

        $onLeave = $this->onLeaveToday();

        $weekMinutes = $this->workspace->timeEntries()
            ->where('clock_in_at', '>=', today()->startOfWeek())
            ->get(['clock_in_at', 'clock_out_at'])
            ->sum(fn ($entry): int => $entry->minutesWorked());

        return [
            'clocked_in_today' => $clockedInToday,
            'on_leave' => $onLeave,
            'not_in' => max(0, $headcount - $clockedInToday - $onLeave),
            'late_today' => $this->workspace->timeEntries()
                ->where('clock_in_at', '>=', today())
                ->where('late_minutes', '>', 0)
                ->distinct()
                ->count('employee_id'),
            'week_minutes' => (int) $weekMinutes,
        ];
    }

    /**
     * Approved leave days taken this year, per leave type, largest first.
     *
     * @return array<int, array{key: int, label: string, count: float}>
     */
    public function leaveByType(): array
    {
        $yearStart = today()->startOfYear();

        $used = $this->workspace->leaveRequests()
            ->approved()
            ->whereDate('starts_on', '>=', $yearStart)
            ->toBase()
            ->select('leave_type_id', DB::raw('sum(days) as used'))
            ->groupBy('leave_type_id')
            ->pluck('used', 'leave_type_id');

        return $this->workspace->leaveTypes()
            ->whereIn('id', $used->keys())
            ->get(['id', 'name'])
            ->map(fn ($type): array => [
                'key' => $type->id,
                'label' => $type->name,
                'count' => (float) $used[$type->id],
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Approved leave touching the coming week, soonest first.
     *
     * @return array<int, array{id: int, name: string, initials: string, avatar_url: string|null, leave_type: string, starts_on: string, ends_on: string}>
     */
    public function outThisWeek(): array
    {
        return $this->workspace->leaveRequests()
            ->approved()
            ->overlapping(today(), today()->addDays(6))
            ->with(['employee.user', 'leaveType'])
            ->orderBy('starts_on')
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'id' => $leave->id,
                'name' => $leave->employee->user->name,
                'initials' => $leave->employee->user->initials(),
                'avatar_url' => $leave->employee->user->avatarUrl(),
                'leave_type' => $leave->leaveType->name,
                'starts_on' => $leave->starts_on->toDateString(),
                'ends_on' => $leave->ends_on->toDateString(),
            ])
            ->all();
    }

    /**
     * Birthdays and work anniversaries in the coming {@see UPCOMING_DAYS},
     * soonest first. An anniversary needs at least one full year.
     *
     * @return array<int, array{employee_id: int, name: string, initials: string, avatar_url: string|null, kind: string, date: string, years: int|null}>
     */
    public function celebrations(): array
    {
        $today = CarbonImmutable::today();
        $until = $today->addDays(self::UPCOMING_DAYS);

        return $this->workspace->employees()
            ->employed()
            ->where(fn ($query) => $query->whereNotNull('birth_date')->orWhereNotNull('hired_on'))
            ->with('user')
            ->get()
            ->flatMap(function (Employee $employee) use ($today, $until): array {
                $person = [
                    'employee_id' => $employee->id,
                    'name' => $employee->user->name,
                    'initials' => $employee->user->initials(),
                    'avatar_url' => $employee->user->avatarUrl(),
                ];

                $events = [];

                if ($employee->birth_date !== null && ($next = $this->nextOccurrence($employee->birth_date, $today)) <= $until) {
                    $events[] = [...$person, 'kind' => 'birthday', 'date' => $next->toDateString(), 'years' => null];
                }

                if ($employee->hired_on !== null && ($next = $this->nextOccurrence($employee->hired_on, $today)) <= $until) {
                    $years = $next->year - $employee->hired_on->year;

                    if ($years >= 1) {
                        $events[] = [...$person, 'kind' => 'anniversary', 'date' => $next->toDateString(), 'years' => $years];
                    }
                }

                return $events;
            })
            ->sortBy('date')
            ->values()
            ->all();
    }

    /**
     * Active contracts of people still employed that end within
     * {@see UPCOMING_DAYS}, soonest first.
     *
     * @return array<int, array{id: int, employee_id: int, name: string, title: string, ends_on: string}>
     */
    public function expiringContracts(): array
    {
        return EmployeeContract::query()
            ->whereIn('employee_id', $this->workspace->employees()->employed()->select('id'))
            ->where('status', ContractStatus::Active)
            ->whereDate('ends_on', '>=', today())
            ->whereDate('ends_on', '<=', today()->addDays(self::UPCOMING_DAYS))
            ->with('employee.user')
            ->orderBy('ends_on')
            ->get()
            ->map(fn (EmployeeContract $contract): array => [
                'id' => $contract->id,
                'employee_id' => $contract->employee_id,
                'name' => $contract->employee->user->name,
                'title' => $contract->title,
                'ends_on' => $contract->ends_on->toDateString(),
            ])
            ->all();
    }

    private function onLeaveToday(): int
    {
        return $this->workspace->leaveRequests()
            ->approved()
            ->overlapping(today(), today())
            ->distinct()
            ->count('employee_id');
    }

    /**
     * The next time `$date`'s month and day come round, today included.
     * A 29 February falls on 28 February in other years.
     */
    private function nextOccurrence(CarbonInterface $date, CarbonImmutable $today): CarbonImmutable
    {
        $next = $this->inYear($date, $today->year);

        return $next->lt($today) ? $this->inYear($date, $today->year + 1) : $next;
    }

    private function inYear(CarbonInterface $date, int $year): CarbonImmutable
    {
        $month = CarbonImmutable::create($year, $date->month, 1);

        return $month->setDay(min($date->day, $month->daysInMonth));
    }
}
