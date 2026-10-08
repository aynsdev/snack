<?php

namespace App\Actions\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ChecklistAssignee;
use App\Enums\ChecklistStatus;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateTask;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts somebody's onboarding or offboarding from a template.
 *
 * Each step is copied onto the person's own checklist and given to a real
 * person: HR steps to whoever started it (or the workspace owner when nobody
 * did), manager steps to the employee's manager (or HR again when they have
 * none), and the employee's own steps to them. Due dates count from the target
 * day — the first day for onboarding, the last for offboarding — or from the
 * start when there is none.
 */
class StartChecklist
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly NotifyChecklist $notify,
    ) {}

    public function handle(
        Employee $employee,
        ChecklistTemplate $template,
        ?User $starter,
        CarbonInterface $startsOn,
        ?CarbonInterface $targetOn = null,
    ): EmployeeChecklist {
        $alreadyRunning = $employee->checklists()->ofKind($template->kind)->inProgress()->exists();

        if ($alreadyRunning) {
            throw ValidationException::withMessages([
                'employee_id' => "{$employee->user->name} already has {$template->kind->label()} under way.",
            ]);
        }

        $employee->loadMissing(['user', 'manager.user', 'workspace.owner']);
        $hr = $starter ?? $employee->workspace->owner;
        $base = $targetOn ?? $startsOn;

        $checklist = DB::transaction(function () use ($employee, $template, $starter, $startsOn, $targetOn, $hr, $base): EmployeeChecklist {
            $checklist = $employee->checklists()->create([
                'workspace_id' => $employee->workspace_id,
                'kind' => $template->kind,
                'name' => $template->name,
                'status' => ChecklistStatus::InProgress,
                'starts_on' => $startsOn->toDateString(),
                'target_on' => $targetOn?->toDateString(),
                'started_by' => $starter?->id,
            ]);

            $checklist->tasks()->createMany($template->tasks->map(fn (ChecklistTemplateTask $task): array => [
                'title' => $task->title,
                'description' => $task->description,
                'assignee_role' => $task->assignee_role,
                'assignee_id' => match ($task->assignee_role) {
                    ChecklistAssignee::Hr => $hr?->id,
                    ChecklistAssignee::Manager => $employee->manager->user_id ?? $hr?->id,
                    ChecklistAssignee::Employee => $employee->user_id,
                },
                'due_on' => $base->addDays($task->due_offset_days)->toDateString(),
                'position' => $task->position,
            ])->all());

            return $checklist;
        });

        $this->audit->handle($employee->workspace, AuditAction::ChecklistStarted, $starter, $employee, newValues: [
            'employee' => $employee->user->name,
            'kind' => $template->kind->value,
            'template' => $template->name,
        ]);

        $this->notify->assigned($checklist, $starter);

        return $checklist;
    }
}
