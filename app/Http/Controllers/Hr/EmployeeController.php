<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AbsenceType;
use App\Enums\AuditAction;
use App\Enums\ContractStatus;
use App\Enums\DisciplinaryActionType;
use App\Enums\EmployeeRecordType;
use App\Enums\EmploymentChange;
use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Enums\ResignationStatus;
use App\Enums\WorkType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\UpdateEmployeeRequest;
use App\Http\Resources\Hr\AbsenceResource;
use App\Http\Resources\Hr\ChecklistTemplateResource;
use App\Http\Resources\Hr\ContractResource;
use App\Http\Resources\Hr\DisciplinaryActionResource;
use App\Http\Resources\Hr\EmployeeChecklistResource;
use App\Http\Resources\Hr\EmployeeRecordResource;
use App\Http\Resources\Hr\EmployeeResource;
use App\Http\Resources\Hr\EmploymentHistoryResource;
use App\Http\Resources\Hr\EquipmentAssignmentResource;
use App\Http\Resources\Hr\LeaveRequestResource;
use App\Http\Resources\Hr\ResignationResource;
use App\Http\Resources\Hr\TimeEntryResource;
use App\Models\Employee;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\Workspace;
use App\Services\Hr\LeaveBalances;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The employee directory and each person's HR file.
 */
class EmployeeController extends Controller
{
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [Employee::class, $workspace]);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $department = $request->query('department');

        $employees = $workspace->employees()
            ->with(['user', 'membership', 'manager.user', 'openTimeEntry'])
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('users.name', 'ilike', "%{$search}%")
                ->orWhere('users.email', 'ilike', "%{$search}%")
                ->orWhere('employees.job_title', 'ilike', "%{$search}%")
                ->orWhere('employees.employee_number', 'ilike', "%{$search}%")))
            ->when(
                is_string($status) && EmploymentStatus::tryFrom($status) !== null,
                fn (Builder $query) => $query->where('employees.status', $status),
                fn (Builder $query) => $query->where('employees.status', '!=', EmploymentStatus::Terminated),
            )
            ->when(is_string($department) && $department !== '', fn (Builder $query) => $query->where('employees.department', $department))
            ->orderBy('users.name')
            ->select('employees.*')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('hr/employees/index', [
            'employees' => EmployeeResource::collection($employees),
            'filters' => [
                'search' => $search,
                'status' => is_string($status) ? $status : null,
                'department' => is_string($department) ? $department : null,
            ],
            'departments' => $workspace->employees()
                ->whereNotNull('department')
                ->distinct()
                ->orderBy('department')
                ->pluck('department')
                ->all(),
            'statuses' => EmploymentStatus::options(),
        ]);
    }

    /**
     * Your own file, wherever you clicked from.
     */
    public function me(Workspace $workspace, Request $request): RedirectResponse
    {
        $employee = $request->user()->employeeIn($workspace);

        abort_if($employee === null, 404);

        return to_route('hr.employees.show', ['workspace' => $workspace->slug, 'employee' => $employee->id]);
    }

    public function show(Workspace $workspace, Employee $employee, Request $request): Response
    {
        $this->authorize('view', $employee);

        $employee->load(['user', 'membership', 'manager.user', 'shift']);
        $canManage = $request->user()->can('update', $employee);

        return Inertia::render('hr/employees/show', [
            'employee' => (new EmployeeResource($employee))->resolve($request),
            'history' => EmploymentHistoryResource::collection(
                $employee->employmentHistories()->with('recorder')->orderByDesc('effective_on')->orderByDesc('id')->get(),
            )->resolve($request),
            'contracts' => ContractResource::collection(
                $employee->contracts()->orderByDesc('starts_on')->get(),
            )->resolve($request),
            // Records are HR's working notes; the employee does not see them.
            'records' => $canManage ? EmployeeRecordResource::collection(
                $employee->records()->with('recorder')->orderByDesc('recorded_on')->orderByDesc('id')->get(),
            )->resolve($request) : [],
            'absences' => AbsenceResource::collection(
                $employee->absences()->with('recorder')->orderByDesc('date')->limit(100)->get(),
            )->resolve($request),
            'leave_requests' => LeaveRequestResource::collection(
                $employee->leaveRequests()->with(['employee.user', 'leaveType', 'reviewer', 'workspace'])->orderByDesc('starts_on')->limit(50)->get(),
            )->resolve($request),
            'balances' => LeaveBalances::for($employee, $workspace),
            'checklists' => $canManage ? EmployeeChecklistResource::collection(
                $employee->checklists()
                    ->with(['employee.user', 'tasks.assignee', 'tasks.completer', 'tasks.checklist.workspace', 'workspace'])
                    ->latest()
                    ->get(),
            )->resolve($request) : [],
            'checklist_templates' => $canManage ? ChecklistTemplateResource::collection(
                $workspace->checklistTemplates()->with('tasks')->orderByDesc('is_default')->orderBy('name')->get(),
            )->resolve($request) : [],
            'resignation' => ($resignation = $employee->resignations()->whereIn('status', [ResignationStatus::Pending, ResignationStatus::Approved])->latest()->with(['employee.user', 'workspace', 'reviewer'])->first()) === null
                ? null
                : (new ResignationResource($resignation))->resolve($request),
            'can_resign' => $employee->user_id === $request->user()->id
                && $request->user()->can('create', [Resignation::class, $workspace]),
            'equipment' => EquipmentAssignmentResource::collection(
                $employee->equipmentAssignments()
                    ->with(['equipment.category', 'equipment.workspace', 'employee.user', 'assigner', 'returner'])
                    ->orderByRaw('returned_on is not null')
                    ->latest('assigned_on')
                    ->limit(50)
                    ->get(),
            )->resolve($request),
            'disciplinary_actions' => DisciplinaryActionResource::collection(
                $employee->disciplinaryActions()->with('issuer')->orderByDesc('starts_on')->orderByDesc('id')->get(),
            )->resolve($request),
            'time_entries' => TimeEntryResource::collection(
                $employee->timeEntries()->latest('clock_in_at')->limit(30)->get(),
            )->resolve($request),
            'managers' => $canManage ? $workspace->employees()
                ->employed()
                ->whereKeyNot($employee->id)
                ->with('user')
                ->get()
                ->map(fn (Employee $manager): array => ['id' => $manager->id, 'name' => $manager->user->name])
                ->sortBy('name')
                ->values()
                ->all() : [],
            'shifts' => $workspace->shifts()
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Shift $shift): array => ['value' => (string) $shift->id, 'label' => "{$shift->name} ({$shift->hours()})"])
                ->all(),
            'options' => [
                'employment_types' => EmploymentType::options(),
                'work_types' => WorkType::options(),
                'statuses' => EmploymentStatus::options(),
                'changes' => EmploymentChange::options(),
                'contract_statuses' => ContractStatus::options(),
                'record_types' => EmployeeRecordType::options(),
                'absence_types' => AbsenceType::options(),
                'disciplinary_types' => DisciplinaryActionType::options(),
            ],
            'can_manage' => $canManage,
        ]);
    }

    public function update(
        Workspace $workspace,
        Employee $employee,
        UpdateEmployeeRequest $request,
        RecordAuditLog $audit,
    ): RedirectResponse {
        $before = $employee->only(array_keys($request->validated()));

        $employee->fill($request->validated());
        $changed = $employee->getDirty();
        $employee->save();

        if ($changed !== []) {
            $audit->handle(
                workspace: $workspace,
                action: AuditAction::EmployeeUpdated,
                actor: $request->user(),
                auditable: $employee,
                oldValues: array_map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value, array_intersect_key($before, $changed)),
                newValues: array_map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value, $changed),
            );
        }

        return back()->with('success', 'Employee updated.');
    }
}
