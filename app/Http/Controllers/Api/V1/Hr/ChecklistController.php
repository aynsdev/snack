<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\CompleteChecklist;
use App\Actions\Hr\StartChecklist;
use App\Enums\ChecklistKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StartChecklistRequest;
use App\Http\Resources\Hr\ChecklistTaskResource;
use App\Http\Resources\Hr\ChecklistTemplateResource;
use App\Http\Resources\Hr\EmployeeChecklistResource;
use App\Http\Resources\Hr\ResignationResource;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistTask;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Onboarding and offboarding for the mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\ChecklistController}.
 * Starting and closing go through the same StartChecklist and
 * CompleteChecklist actions, so the steps, assignees, and the employment
 * ending on a completed offboarding all happen exactly as on the web.
 */
class ChecklistController extends Controller
{
    /** How many finished or cancelled checklists stay listed. */
    private const RECENT = 10;

    /** What every checklist needs loaded to be rendered. */
    private const LOAD = ['employee.user', 'tasks.assignee', 'tasks.completer', 'tasks.checklist.workspace', 'workspace'];

    public function onboarding(Workspace $workspace, Request $request): JsonResponse
    {
        return $this->board($workspace, $request, ChecklistKind::Onboarding);
    }

    public function offboarding(Workspace $workspace, Request $request): JsonResponse
    {
        return $this->board($workspace, $request, ChecklistKind::Offboarding);
    }

    public function store(Workspace $workspace, StartChecklistRequest $request, StartChecklist $start): JsonResponse
    {
        $employee = $workspace->employees()->with('user')->findOrFail($request->integer('employee_id'));
        $template = $workspace->checklistTemplates()->with('tasks')->findOrFail($request->integer('checklist_template_id'));

        $checklist = $start->handle(
            $employee,
            $template,
            $request->user(),
            Carbon::parse($request->validated('starts_on')),
            $request->filled('target_on') ? Carbon::parse($request->validated('target_on')) : null,
        );

        return (new EmployeeChecklistResource($checklist->fresh(self::LOAD)))->response()->setStatusCode(201);
    }

    public function complete(Workspace $workspace, EmployeeChecklist $employeeChecklist, Request $request, CompleteChecklist $close): EmployeeChecklistResource
    {
        $this->authorize('close', $employeeChecklist);

        $close->complete($employeeChecklist, $request->user());

        return new EmployeeChecklistResource($employeeChecklist->fresh(self::LOAD));
    }

    public function cancel(Workspace $workspace, EmployeeChecklist $employeeChecklist, Request $request, CompleteChecklist $close): EmployeeChecklistResource
    {
        $this->authorize('close', $employeeChecklist);

        $close->cancel($employeeChecklist, $request->user());

        return new EmployeeChecklistResource($employeeChecklist->fresh(self::LOAD));
    }

    /**
     * Ticks a step, or unticks it — the step comes back as it now stands.
     */
    public function toggle(Workspace $workspace, EmployeeChecklistTask $employeeChecklistTask, Request $request): ChecklistTaskResource
    {
        $this->authorize('toggle', $employeeChecklistTask);

        $done = ! $employeeChecklistTask->isDone();

        $employeeChecklistTask->update([
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $request->user()->id : null,
        ]);

        return new ChecklistTaskResource($employeeChecklistTask->fresh(['assignee', 'completer', 'checklist.employee.user', 'checklist.workspace']));
    }

    /**
     * The board: what is under way, what recently finished, the templates
     * to start from, the people who could be started on, and — offboarding
     * only — resignations waiting on HR.
     */
    private function board(Workspace $workspace, Request $request, ChecklistKind $kind): JsonResponse
    {
        $this->authorize('viewAny', [EmployeeChecklist::class, $workspace]);

        ChecklistTemplate::seedDefaultsFor($workspace);

        return response()->json(['data' => [
            'kind' => $kind->value,
            'active' => EmployeeChecklistResource::collection(
                $workspace->employeeChecklists()->ofKind($kind)->inProgress()->with(self::LOAD)->orderBy('target_on')->orderBy('starts_on')->get(),
            )->resolve($request),
            'recent' => EmployeeChecklistResource::collection(
                $workspace->employeeChecklists()->ofKind($kind)->whereNot('status', 'in_progress')->with(self::LOAD)->latest('updated_at')->limit(self::RECENT)->get(),
            )->resolve($request),
            'templates' => ChecklistTemplateResource::collection(
                $workspace->checklistTemplates()->ofKind($kind)->with('tasks')->orderByDesc('is_default')->orderBy('name')->get(),
            )->resolve($request),
            'employees' => $workspace->employees()
                ->employed()
                ->with('user')
                ->get()
                ->map(fn (Employee $employee): array => ['value' => (string) $employee->id, 'label' => $employee->user->name])
                ->sortBy('label')
                ->values()
                ->all(),
            'resignations' => $kind === ChecklistKind::Offboarding ? ResignationResource::collection(
                $workspace->resignations()->pending()->with(['employee.user', 'workspace'])->orderBy('last_day_on')->get(),
            )->resolve($request) : [],
        ]]);
    }
}
