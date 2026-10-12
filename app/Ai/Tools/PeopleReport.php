<?php

namespace App\Ai\Tools;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * "Who has the most absences this month?" — "who is performing well?"
 *
 * One row per employee over a period: days and hours worked, lateness,
 * undertime, overtime, absences by type, approved leave, and what they got
 * done on the boards. Ranked by whichever figure the question is about.
 *
 * # Only for HR, only in private
 *
 * This is the one tool whose data is about people rather than for them, so it
 * is not enough that the asker belongs to the workspace. {@see App\Ai\Agents\Bite}
 * only builds it when {@see App\Ai\BiteContext::mayReadPeopleRecords()} holds —
 * an admin, in a DM with Bite alone — and `handle()` checks again, because a
 * tool that is safe only as long as its caller remembers is not safe.
 *
 * # Performance is not a figure here
 *
 * Snack has no performance reviews. "Performing well" can only be read off
 * attendance and task output, and the result says so in its first line, so the
 * model presents a judgement it made from these numbers rather than one it
 * implies HR recorded.
 *
 * @phpstan-type EmployeeFigures array{employee: Employee, days: int, hours: float, late_days: int, late: int, undertime_days: int, undertime: int, overtime: int, absences: int, absence_types: array<string, int>, leave: float, tasks_completed: int, story_points: int, overdue: int}
 */
class PeopleReport extends BiteTool implements Tool
{
    /**
     * The figures a report can be ranked by, and what each one is called.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'absences' => 'absences',
        'late' => 'minutes late',
        'undertime' => 'minutes of undertime',
        'overtime' => 'minutes of overtime',
        'hours' => 'hours worked',
        'tasks_completed' => 'tasks completed',
        'story_points' => 'story points completed',
        'overdue' => 'overdue tasks',
        'name' => 'name',
    ];

    /**
     * The longest period one report covers.
     *
     * Wide enough for "this year"; narrow enough that a model asking for
     * "all time" gets a bounded query rather than every clock-in ever made.
     */
    private const MAX_DAYS = 366;

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Attendance and output figures for this workspace's employees over a
        period: days and hours worked, how often and how many minutes late,
        undertime (minutes left before the shift ended), overtime, absences by
        type, approved leave days, and tasks completed, story points, and
        overdue tasks.

        Use it for HR questions: who is often late, who has the most absences
        or undertime, who is doing well. Rank with `sort_by`. Snack has no
        performance reviews, so "performing well" means good attendance and
        strong task output — say that when you answer.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->context->mayReadPeopleRecords()) {
            return 'Attendance and performance figures are only available to workspace admins, '
                .'in a direct message with Bite.';
        }

        [$from, $to] = $this->period($this->arg($request, 'from'), $this->arg($request, 'to'));

        $sort = $this->arg($request, 'sort_by', 'name');
        $sort = is_string($sort) && array_key_exists($sort, self::SORTS) ? $sort : 'name';
        $limit = $this->limit($this->arg($request, 'limit'), 15, 50);
        $person = ltrim($this->term($this->arg($request, 'person'), 60), '@');
        $department = $this->term($this->arg($request, 'department'), 60);

        $employees = Employee::query()
            ->where('workspace_id', $this->context->workspace->id)
            ->employed()
            ->when($department !== '', fn (Builder $query) => $query->where('department', 'ilike', $department))
            ->when($person !== '', fn (Builder $query) => $query->whereHas(
                'user',
                fn (Builder $user) => $user
                    ->where('username', 'ilike', $person)
                    ->orWhere('name', 'ilike', '%'.$person.'%'),
            ))
            ->with('user:id,name,username')
            ->get();

        if ($employees->isEmpty()) {
            return 'No employee records match that. Only people with an HR employee record are counted.';
        }

        $rows = collect($this->figures($employees, $from, $to));

        $ranked = $sort === 'name'
            ? $rows->sortBy(fn (array $row): string => mb_strtolower($row['employee']->user->name))
            : $rows->sortByDesc(fn (array $row): float => $row[$sort]);

        $shown = $ranked->take($limit);

        $header = "Period: {$from->toFormattedDateString()} to {$to->toFormattedDateString()}. "
            .'Snack has no performance reviews; these are attendance and task figures only. '
            .'Late and undertime count only beyond each shift\'s grace minutes.'
            ."\nRanked by ".self::SORTS[$sort].". Showing {$shown->count()} of {$rows->count()} employees.";

        return $header."\n\n".implode("\n", $shown->map(fn (array $row): string => $this->row($row))->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()
                ->description('Start of the period, YYYY-MM-DD. Defaults to the first day of this month.'),
            'to' => $schema->string()
                ->description('End of the period, YYYY-MM-DD, inclusive. Defaults to today. At most a year after `from`.'),
            'sort_by' => $schema->string()
                ->description('Which figure to rank by, highest first. Defaults to name.')
                ->enum(array_keys(self::SORTS)),
            'person' => $schema->string()
                ->description('Optional: one person, by username or part of their name.'),
            'department' => $schema->string()
                ->description('Optional: only employees in this department.'),
            'limit' => $schema->integer()
                ->description('How many employees to return. Defaults to 15, maximum 50.')
                ->min(1)
                ->max(50),
        ];
    }

    /**
     * The period asked for, made sane.
     *
     * Both ends come from the model, so either may be missing, malformed, or
     * the wrong way round; none of those is worth failing the answer over.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(mixed $from, mixed $to): array
    {
        $today = CarbonImmutable::today(config('app.timezone'));

        $start = $this->date($from) ?? $today->startOfMonth();
        $end = $this->date($to) ?? $today;

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS);
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every figure for every employee, in four queries.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, EmployeeFigures>
     */
    private function figures(Collection $employees, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $employeeIds = $employees->pluck('id');
        $userIds = $employees->pluck('user_id');

        $attendance = TimeEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('clock_in_at', [$from, $to])
            ->groupBy('employee_id')
            ->selectRaw(<<<'SQL'
                employee_id,
                count(distinct date(clock_in_at)) as days,
                coalesce(sum(extract(epoch from (clock_out_at - clock_in_at))) / 3600, 0) as hours,
                count(*) filter (where late_minutes > 0) as late_days,
                coalesce(sum(late_minutes), 0) as late,
                count(*) filter (where early_out_minutes > 0) as undertime_days,
                coalesce(sum(early_out_minutes), 0) as undertime,
                coalesce(sum(overtime_minutes), 0) as overtime
                SQL)
            ->get()
            ->keyBy('employee_id');

        $absences = Absence::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['employee_id', 'type'])
            ->groupBy('employee_id');

        $leave = LeaveRequest::query()
            ->approved()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('starts_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('employee_id')
            ->selectRaw('employee_id, sum(days) as days')
            ->toBase()
            ->pluck('days', 'employee_id');

        $tasks = Task::query()
            ->whereHas('project', fn (Builder $project) => $project->where('workspace_id', $this->context->workspace->id))
            ->whereIn('assignee_id', $userIds)
            ->groupBy('assignee_id')
            ->selectRaw('assignee_id, count(*) filter (where completed_at between ? and ?) as completed', [$from, $to])
            ->selectRaw('coalesce(sum(story_points) filter (where completed_at between ? and ?), 0) as points', [$from, $to])
            ->selectRaw('count(*) filter (where completed_at is null and due_on < ?) as overdue', [CarbonImmutable::today(config('app.timezone'))->toDateString()])
            ->toBase()
            ->get()
            ->keyBy('assignee_id');

        return $employees->map(function (Employee $employee) use ($attendance, $absences, $leave, $tasks): array {
            $time = $attendance->get($employee->id);
            $types = [];

            foreach ($absences->get($employee->id, []) as $absence) {
                $types[$absence->type->value] = ($types[$absence->type->value] ?? 0) + 1;
            }

            $work = $tasks->get($employee->user_id);

            return [
                'employee' => $employee,
                'days' => (int) ($time->days ?? 0),
                'hours' => round((float) ($time->hours ?? 0), 1),
                'late_days' => (int) ($time->late_days ?? 0),
                'late' => (int) ($time->late ?? 0),
                'undertime_days' => (int) ($time->undertime_days ?? 0),
                'undertime' => (int) ($time->undertime ?? 0),
                'overtime' => (int) ($time->overtime ?? 0),
                'absences' => array_sum($types),
                'absence_types' => $types,
                'leave' => (float) ($leave->get($employee->id) ?? 0),
                'tasks_completed' => (int) ($work->completed ?? 0),
                'story_points' => (int) ($work->points ?? 0),
                'overdue' => (int) ($work->overdue ?? 0),
            ];
        })->values()->all();
    }

    /**
     * One employee, as the model should see them.
     *
     * @param  EmployeeFigures  $row
     */
    private function row(array $row): string
    {
        $employee = $row['employee'];
        $role = collect([$employee->job_title, $employee->department])->filter()->implode(', ');

        $absences = $row['absences'] === 0
            ? 'no absences'
            : "{$row['absences']} ".str('absence')->plural($row['absences']).' ('.collect($row['absence_types'])
                ->map(fn (int $count, string $type): string => "{$count} {$type}")
                ->implode(', ').')';

        return "{$employee->user->name} (@{$employee->user->username})".($role !== '' ? " — {$role}" : '')
            ."\n  attendance: {$row['days']} days, {$row['hours']} h worked; "
            ."late {$row['late_days']}× ({$row['late']} min); "
            ."undertime {$row['undertime_days']}× ({$row['undertime']} min); "
            ."overtime {$row['overtime']} min"
            ."\n  {$absences}; approved leave {$row['leave']} days"
            ."\n  tasks: {$row['tasks_completed']} completed ({$row['story_points']} points), {$row['overdue']} overdue now";
    }
}
