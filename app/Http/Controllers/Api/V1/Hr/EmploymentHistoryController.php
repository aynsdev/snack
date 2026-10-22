<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\EmploymentChange;
use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreEmploymentHistoryRequest;
use App\Http\Resources\Hr\EmploymentHistoryResource;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Promotions, transfers, and terminations on somebody's employment timeline.
 *
 * With `apply_to_profile`, the change also updates the employee's current job
 * title, department, and type, and a termination or rehire changes their
 * status — exactly as the web app does.
 */
class EmploymentHistoryController extends Controller
{
    public function store(
        Workspace $workspace,
        Employee $employee,
        StoreEmploymentHistoryRequest $request,
        RecordAuditLog $audit,
    ): JsonResponse {
        $change = EmploymentChange::from($request->string('change')->toString());

        $entry = $employee->employmentHistories()->create([
            ...$request->safe()->except('apply_to_profile'),
            'recorded_by' => $request->user()->id,
        ]);

        if ($request->boolean('apply_to_profile')) {
            $updates = array_filter([
                'job_title' => $entry->job_title,
                'department' => $entry->department,
                'employment_type' => $entry->employment_type instanceof EmploymentType ? $entry->employment_type : null,
            ], fn (mixed $value): bool => $value !== null);

            if ($change === EmploymentChange::Terminated) {
                $updates['status'] = EmploymentStatus::Terminated;
                $updates['terminated_on'] = $entry->effective_on;
            }

            if ($change === EmploymentChange::Rehired) {
                $updates['status'] = EmploymentStatus::Active;
                $updates['terminated_on'] = null;
            }

            $employee->update($updates);
        }

        $audit->handle(
            workspace: $workspace,
            action: AuditAction::EmploymentChanged,
            actor: $request->user(),
            auditable: $employee,
            newValues: [
                'change' => $change->value,
                'effective_on' => $entry->effective_on->toDateString(),
                'job_title' => $entry->job_title,
                'department' => $entry->department,
            ],
        );

        return (new EmploymentHistoryResource($entry->load('recorder')))->response()->setStatusCode(201);
    }

    public function destroy(
        Workspace $workspace,
        Employee $employee,
        EmploymentHistory $employmentHistory,
        Request $request,
    ): Response {
        $this->authorize('update', $employee);

        $employmentHistory->delete();

        return response()->noContent();
    }
}
