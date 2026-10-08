<?php

namespace App\Http\Resources\Hr;

use App\Models\EmployeeChecklistTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeChecklistTask
 */
class ChecklistTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'assignee_role' => $this->assignee_role->value,
            'assignee' => $this->assignee?->name,
            'due_on' => $this->due_on?->toDateString(),
            'is_overdue' => ! $this->isDone() && $this->due_on?->lt(today()) === true,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->completer?->name,
            'checklist' => $this->whenLoaded('checklist', fn (): array => [
                'id' => $this->checklist->id,
                'kind' => $this->checklist->kind->value,
                'kind_label' => $this->checklist->kind->label(),
                'employee' => $this->checklist->employee->user->name,
            ]),
            'can_toggle' => $request->user()?->can('toggle', $this->resource) ?? false,
        ];
    }
}
