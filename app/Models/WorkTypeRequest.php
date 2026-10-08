<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\WorkType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Asking to work somewhere else — remote, on-site, hybrid — for good when
 * there is no end date, or just for the days between the two dates.
 *
 * @property WorkType $work_type
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
#[Fillable([
    'workspace_id', 'employee_id', 'work_type', 'starts_on', 'ends_on', 'reason',
    'status', 'reviewer_id', 'reviewed_at', 'review_note',
])]
class WorkTypeRequest extends HrRequest
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'work_type' => WorkType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * Requests whose dates include the given day.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeCovering(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    public function isPermanent(): bool
    {
        return $this->ends_on === null;
    }

    public function kind(): string
    {
        return 'work type change';
    }

    public function summary(): string
    {
        return $this->work_type->label().', '.ShiftRequest::span($this->starts_on, $this->ends_on);
    }

    /**
     * Like a shift change: only a permanent one is written to the file.
     */
    public function applyApproval(): void
    {
        if ($this->isPermanent()) {
            $this->employee->update(['work_type' => $this->work_type]);
        }
    }

    public function auditAction(bool $approved): AuditAction
    {
        return $approved ? AuditAction::WorkTypeRequestApproved : AuditAction::WorkTypeRequestRejected;
    }
}
