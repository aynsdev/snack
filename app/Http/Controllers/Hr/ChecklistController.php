<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\CompleteChecklist;
use App\Actions\Hr\StartChecklist;
use App\Enums\ChecklistKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StartChecklistRequest;
use App\Http\Resources\Hr\ChecklistTemplateResource;
use App\Http\Resources\Hr\EmployeeChecklistResource;
use App\Http\Resources\Hr\ResignationResource;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistTask;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Onboarding and offboarding: HR's boards of who is coming and going, and the
 * steps everybody ticks off along the way.
 */
class ChecklistController extends Controller
{
    /** How many finished or cancelled checklists stay listed. */
    private const RECENT = 10;

    public function onboarding(Workspace $workspace, Request $request): Response
    {
        return $this->board($workspace, $request, ChecklistKind::Onboarding);
    }

    public function offboarding(Workspace $workspace, Request $request): Response
    {
        return $this->board($workspace, $request, ChecklistKind::Offboarding);
    }

    public function store(Workspace $workspace, StartChecklistRequest $request, StartChecklist $start): RedirectResponse
    {
        $employee = $workspace->employees()->with('user')->findOrFail($request->integer('employee_id'));
        $template = $workspace->checklistTemplates()->with('tasks')->findOrFail($request->integer('checklist_template_id'));

        $start->handle(
            $employee,
            $template,
            $request->user(),
            Carbon::parse($request->validated('starts_on')),
            $request->filled('target_on') ? Carbon::parse($request->validated('target_on')) : null,
        );

        return back()->with('success', "{$template->kind->label()} started for {$employee->user->name}.");
    }

    public function complete(Workspace $workspace, EmployeeChecklist $employeeChecklist, Request $request, CompleteChecklist $close): RedirectResponse
    {
        $this->authorize('close', $employeeChecklist);

        $close->complete($employeeChecklist, $request->user());

        return back()->with('success', "{$employeeChecklist->kind->label()} completed for {$employeeChecklist->employee->user->name}.");
    }

    public function cancel(Workspace $workspace, EmployeeChecklist $employeeChecklist, Request $request, CompleteChecklist $close): RedirectResponse
    {
        $this->authorize('close', $employeeChecklist);

        $close->cancel($employeeChecklist, $request->user());

        return back()->with('success', "{$employeeChecklist->kind->label()} cancelled.");
    }

    public function toggle(Workspace $workspace, EmployeeChecklistTask $employeeChecklistTask, Request $request): RedirectResponse
    {
        $this->authorize('toggle', $employeeChecklistTask);

        $done = ! $employeeChecklistTask->isDone();

        $employeeChecklistTask->update([
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $request->user()->id : null,
        ]);

        return back();
    }

    private function board(Workspace $workspace, Request $request, ChecklistKind $kind): Response
    {
        $this->authorize('viewAny', [EmployeeChecklist::class, $workspace]);

        ChecklistTemplate::seedDefaultsFor($workspace);

        $load = ['employee.user', 'tasks.assignee', 'tasks.completer', 'tasks.checklist.workspace', 'workspace'];

        return Inertia::render('hr/checklists', [
            'kind' => $kind->value,
            'active' => EmployeeChecklistResource::collection(
                $workspace->employeeChecklists()->ofKind($kind)->inProgress()->with($load)->orderBy('target_on')->orderBy('starts_on')->get(),
            )->resolve($request),
            'recent' => EmployeeChecklistResource::collection(
                $workspace->employeeChecklists()->ofKind($kind)->whereNot('status', 'in_progress')->with($load)->latest('updated_at')->limit(self::RECENT)->get(),
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
        ]);
    }
}
