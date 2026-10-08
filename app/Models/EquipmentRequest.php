<?php

namespace App\Models;

use App\Actions\Hr\HandOverEquipment;
use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee asking for a piece of equipment of some kind. HR can approve it
 * and hand something over in the same step.
 *
 * @property int $equipment_category_id
 * @property int|null $equipment_assignment_id
 * @property-read EquipmentCategory $category
 * @property-read EquipmentAssignment|null $assignment
 */
#[Fillable([
    'workspace_id', 'employee_id', 'equipment_category_id', 'reason', 'equipment_assignment_id',
    'status', 'reviewer_id', 'reviewed_at', 'review_note',
])]
class EquipmentRequest extends HrRequest
{
    /**
     * What HR chose to hand over on approval. Set just before approving; not
     * stored — the resulting assignment is.
     */
    public ?Equipment $allocation = null;

    /**
     * @return BelongsTo<EquipmentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }

    /**
     * @return BelongsTo<EquipmentAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EquipmentAssignment::class, 'equipment_assignment_id');
    }

    public function kind(): string
    {
        return 'equipment request';
    }

    public function summary(): string
    {
        return $this->category->name.($this->allocation ? " ({$this->allocation->name})" : '');
    }

    /**
     * Hands over whatever HR picked, if anything. Approving without picking
     * leaves the hand-over for later, from the equipment list.
     */
    public function applyApproval(): void
    {
        if ($this->allocation === null) {
            return;
        }

        /** @var User $reviewer */
        $reviewer = $this->reviewer()->firstOrFail();

        $assignment = app(HandOverEquipment::class)->assign($this->allocation, $this->employee, $reviewer);

        $this->update(['equipment_assignment_id' => $assignment->id]);
    }

    public function auditAction(bool $approved): AuditAction
    {
        return $approved ? AuditAction::EquipmentRequestApproved : AuditAction::EquipmentRequestRejected;
    }
}
