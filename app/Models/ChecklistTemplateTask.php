<?php

namespace App\Models;

use App\Enums\ChecklistAssignee;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a {@see ChecklistTemplate}.
 *
 * @property int $id
 * @property int $checklist_template_id
 * @property string $title
 * @property string|null $description
 * @property ChecklistAssignee $assignee_role
 * @property int $due_offset_days
 * @property int $position
 */
#[Fillable(['checklist_template_id', 'title', 'description', 'assignee_role', 'due_offset_days', 'position'])]
class ChecklistTemplateTask extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignee_role' => ChecklistAssignee::class,
            'due_offset_days' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ChecklistTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }
}
