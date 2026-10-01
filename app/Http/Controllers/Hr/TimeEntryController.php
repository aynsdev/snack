<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\UpdateTimeEntryRequest;
use App\Http\Resources\Hr\TimeEntryResource;
use App\Models\Employee;
use App\Models\TimeEntry;
use App\Models\Workspace;
use App\Support\Geofence;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Timesheets: yours, or — for HR — everybody's, with where each shift was
 * clocked from.
 */
class TimeEntryController extends Controller
{
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('view', $workspace);

        $user = $request->user();
        $canManage = $user->can('viewAny', [TimeEntry::class, $workspace]);
        $employee = $user->employeeIn($workspace);
        $employee?->load('openTimeEntry');

        [$from, $to] = $this->range($request);

        $employeeId = $canManage && $request->filled('employee')
            ? $request->integer('employee')
            : null;

        $scope = $canManage && $request->query('scope') === 'team' ? 'team' : 'mine';
        $ownEmployeeId = $employee === null ? 0 : $employee->id;

        $entries = $workspace->timeEntries()
            ->with('employee.user')
            ->whereBetween('clock_in_at', [$from, $to])
            ->when(
                $scope === 'team',
                fn ($query) => $query->when($employeeId !== null, fn ($query) => $query->where('employee_id', $employeeId)),
                fn ($query) => $query->where('employee_id', $ownEmployeeId),
            )
            ->latest('clock_in_at')
            ->limit(500)
            ->get();

        return Inertia::render('hr/time', [
            'entries' => TimeEntryResource::collection($entries)->resolve($request),
            'total_minutes' => $entries->sum(fn (TimeEntry $entry): int => $entry->minutesWorked()),
            'open_entry' => $employee?->openTimeEntry === null
                ? null
                : (new TimeEntryResource($employee->openTimeEntry))->resolve($request),
            'has_employee' => $employee !== null,
            'geofence' => Geofence::forWorkspace($workspace)?->toArray(),
            'require_location' => (bool) $workspace->setting('hr.require_location', false),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'scope' => $scope,
                'employee' => $employeeId,
            ],
            'employees' => $canManage ? $workspace->employees()
                ->with('user')
                ->get()
                ->map(fn (Employee $person): array => ['id' => $person->id, 'name' => $person->user->name])
                ->sortBy('name')
                ->values()
                ->all() : [],
            'can_manage' => $canManage,
        ]);
    }

    public function update(
        Workspace $workspace,
        TimeEntry $timeEntry,
        UpdateTimeEntryRequest $request,
        RecordAuditLog $audit,
    ): RedirectResponse {
        $before = [
            'clock_in_at' => $timeEntry->clock_in_at->toIso8601String(),
            'clock_out_at' => $timeEntry->clock_out_at?->toIso8601String(),
        ];

        $timeEntry->update($request->times());

        $audit->handle($workspace, AuditAction::TimeEntryUpdated, $request->user(), $timeEntry, $before, [
            'employee' => $timeEntry->employee->user->name,
            'clock_in_at' => $timeEntry->clock_in_at->toIso8601String(),
            'clock_out_at' => $timeEntry->clock_out_at?->toIso8601String(),
        ]);

        return back()->with('success', 'Time entry corrected.');
    }

    public function destroy(
        Workspace $workspace,
        TimeEntry $timeEntry,
        Request $request,
        RecordAuditLog $audit,
    ): RedirectResponse {
        $this->authorize('delete', $timeEntry);

        $timeEntry->delete();

        $audit->handle($workspace, AuditAction::TimeEntryDeleted, $request->user(), $timeEntry->employee, oldValues: [
            'clock_in_at' => $timeEntry->clock_in_at->toIso8601String(),
            'clock_out_at' => $timeEntry->clock_out_at?->toIso8601String(),
        ]);

        return back()->with('success', 'Time entry deleted.');
    }

    /**
     * The requested date range, defaulting to the current week and capped at
     * a year so a hand-edited URL cannot ask for the whole history at once.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function range(Request $request): array
    {
        $from = $this->date($request->query('from')) ?? today()->startOfWeek();
        $to = $this->date($request->query('to')) ?? today()->endOfWeek();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subYear();
        }

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    private function date(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay() ?: null;
    }
}
