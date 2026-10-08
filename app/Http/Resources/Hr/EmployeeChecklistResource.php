<?php

namespace App\Http\Resources\Hr;

use App\Enums\ChecklistKind;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeChecklist
 */
class EmployeeChecklistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'employee' => [
                'id' => $this->employee->id,
                'name' => $this->employee->user->name,
                'initials' => $this->employee->user->initials(),
                'avatar_url' => $this->employee->user->avatarUrl(),
                'job_title' => $this->employee->job_title,
            ],
            'starts_on' => $this->starts_on->toDateString(),
            'target_on' => $this->target_on?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'done' => $this->tasks->filter(fn (EmployeeChecklistTask $task): bool => $task->isDone())->count(),
            'total' => $this->tasks->count(),
            'tasks' => ChecklistTaskResource::collection($this->tasks)->resolve($request),
            // What the person leaving still has to hand back.
            'equipment_held' => $this->kind === ChecklistKind::Offboarding && $this->status->isInProgress()
                ? $this->employee->equipmentAssignments()->open()->count()
                : null,
            'can_close' => $request->user()?->can('close', $this->resource) ?? false,
        ];
    }
}
