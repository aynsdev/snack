<?php

namespace App\Actions\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ChecklistKind;
use App\Enums\ChecklistStatus;
use App\Enums\EmploymentChange;
use App\Enums\EmploymentStatus;
use App\Models\EmployeeChecklist;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closes somebody's onboarding or offboarding.
 *
 * Finishing offboarding is what ends the employment: the person is marked
 * terminated as of their last day and it goes on their history. Their
 * workspace access is left alone — removing or suspending a member is its own,
 * deliberate step in member management.
 */
class CompleteChecklist
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function complete(EmployeeChecklist $checklist, User $actor): EmployeeChecklist
    {
        DB::transaction(function () use ($checklist): void {
            $checklist->update(['status' => ChecklistStatus::Completed, 'completed_at' => now()]);

            if ($checklist->kind === ChecklistKind::Offboarding) {
                $employee = $checklist->employee;
                $lastDay = $checklist->target_on ?? today();

                $employee->update(['status' => EmploymentStatus::Terminated, 'terminated_on' => $lastDay]);

                $employee->employmentHistories()->create([
                    'change' => EmploymentChange::Terminated,
                    'effective_on' => $lastDay->toDateString(),
                    'job_title' => $employee->job_title,
                    'department' => $employee->department,
                    'employment_type' => $employee->employment_type,
                    'notes' => 'Offboarding completed.',
                ]);
            }
        });

        $this->record($checklist, $actor, AuditAction::ChecklistCompleted);

        return $checklist;
    }

    public function cancel(EmployeeChecklist $checklist, User $actor): EmployeeChecklist
    {
        $checklist->update(['status' => ChecklistStatus::Cancelled]);

        $this->record($checklist, $actor, AuditAction::ChecklistCancelled);

        return $checklist;
    }

    private function record(EmployeeChecklist $checklist, User $actor, AuditAction $action): void
    {
        $this->audit->handle($checklist->workspace, $action, $actor, $checklist->employee, newValues: [
            'employee' => $checklist->employee->user->name,
            'kind' => $checklist->kind->value,
        ]);
    }
}
