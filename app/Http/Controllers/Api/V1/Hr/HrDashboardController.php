<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hr\AnnouncementResource;
use App\Http\Resources\Hr\ChecklistTaskResource;
use App\Http\Resources\Hr\LeaveRequestResource;
use App\Http\Resources\Hr\TimeEntryResource;
use App\Models\Employee;
use App\Models\EmployeeChecklistTask;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Workspace;
use App\Services\Hr\LeaveBalances;
use App\Support\Geofence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HR home: your clock, your leave, your onboarding and offboarding steps,
 * what the company announced, the next holidays, and — for admins — what is
 * waiting on them. The same page the web app renders at hr.index.
 */
class HrDashboardController extends Controller
{
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('view', $workspace);

        LeaveType::seedDefaultsFor($workspace);

        $user = $request->user();
        $employee = $user->employeeIn($workspace);
        $employee?->load(['openTimeEntry', 'workspace']);
        $canManage = $user->roleIn($workspace)?->canManagePeople() === true;

        return response()->json(['data' => [
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'job_title' => $employee->job_title,
                'department' => $employee->department,
                'status_label' => $employee->status->label(),
            ],
            'clock' => $employee === null ? null : $this->clockState($employee, $request),
            'geofence' => Geofence::forWorkspace($workspace)?->toArray(),
            'require_location' => (bool) $workspace->setting('hr.require_location', false),
            'balances' => $employee === null ? [] : LeaveBalances::for($employee, $workspace),
            'my_requests' => $employee === null ? [] : LeaveRequestResource::collection(
                $employee->leaveRequests()->with(['employee.user', 'leaveType', 'reviewer', 'workspace'])->latest()->limit(5)->get(),
            )->resolve($request),
            'announcements' => AnnouncementResource::collection(
                $workspace->announcements()->with('author')->orderByDesc('is_pinned')->latest()->limit(5)->get(),
            )->resolve($request),
            'upcoming_holidays' => $this->upcomingHolidays($workspace),
            'out_today' => $workspace->leaveRequests()
                ->approved()
                ->overlapping(today(), today())
                ->with(['employee.user', 'leaveType'])
                ->get()
                ->map(fn (LeaveRequest $leave): array => [
                    'id' => $leave->id,
                    'name' => $leave->employee->user->name,
                    'initials' => $leave->employee->user->initials(),
                    'avatar_url' => $leave->employee->user->avatarUrl(),
                    'leave_type' => $leave->leaveType->name,
                    'ends_on' => $leave->ends_on->toDateString(),
                ])
                ->all(),
            'stats' => $canManage ? [
                'headcount' => $workspace->employees()->employed()->count(),
                'pending_leave' => $workspace->leaveRequests()->where('status', LeaveStatus::Pending)->count(),
                'clocked_in' => $workspace->timeEntries()->whereNull('clock_out_at')->count(),
            ] : null,
            'my_tasks' => ChecklistTaskResource::collection(
                EmployeeChecklistTask::query()
                    ->where('assignee_id', $user->id)
                    ->whereNull('completed_at')
                    ->whereIn('employee_checklist_id', $workspace->employeeChecklists()->inProgress()->select('id'))
                    ->with(['checklist.employee.user', 'checklist.workspace'])
                    ->orderByRaw('due_on is null')
                    ->orderBy('due_on')
                    ->limit(20)
                    ->get(),
            )->resolve($request),
            'can_manage' => $canManage,
        ]]);
    }

    /**
     * @return array{open_entry: array<string, mixed>|null, today_minutes: int}
     */
    private function clockState(Employee $employee, Request $request): array
    {
        $todayMinutes = $employee->timeEntries()
            ->where('clock_in_at', '>=', today())
            ->get()
            ->sum(fn ($entry): int => $entry->minutesWorked());

        return [
            'open_entry' => $employee->openTimeEntry === null
                ? null
                : (new TimeEntryResource($employee->openTimeEntry))->resolve($request),
            'today_minutes' => (int) $todayMinutes,
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, date: string}>
     */
    private function upcomingHolidays(Workspace $workspace): array
    {
        return $workspace->holidays()
            ->where(fn ($query) => $query->where('is_recurring', true)->orWhereDate('date', '>=', today()))
            ->get()
            ->map(fn (Holiday $holiday): ?array => ($next = $holiday->nextOccurrence()) === null ? null : [
                'id' => $holiday->id,
                'name' => $holiday->name,
                'date' => $next->toDateString(),
            ])
            ->filter()
            ->sortBy('date')
            ->take(5)
            ->values()
            ->all();
    }
}
