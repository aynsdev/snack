<?php

namespace App\Http\Resources\Hr;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChecklistTemplate
 */
class ChecklistTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'tasks' => $this->tasks->map(fn (ChecklistTemplateTask $task): array => [
                'title' => $task->title,
                'description' => $task->description,
                'assignee_role' => $task->assignee_role->value,
                'due_offset_days' => $task->due_offset_days,
            ])->all(),
        ];
    }
}
