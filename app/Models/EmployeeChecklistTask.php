<?php

namespace App\Models;

use App\Enums\ChecklistAssignee;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of somebody's onboarding or offboarding.
 *
 * @property int $id
 * @property int $employee_checklist_id
 * @property string $title
 * @property string|null $description
 * @property ChecklistAssignee $assignee_role
 * @property int|null $assignee_id
 * @property Carbon|null $due_on
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property int $position
 * @property-read EmployeeChecklist $checklist
 * @property-read User|null $assignee
 * @property-read User|null $completer
 */
#[Fillable([
    'employee_checklist_id', 'title', 'description', 'assignee_role', 'assignee_id',
    'due_on', 'completed_at', 'completed_by', 'position',
])]
class EmployeeChecklistTask extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignee_role' => ChecklistAssignee::class,
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EmployeeChecklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(EmployeeChecklist::class, 'employee_checklist_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }
}
