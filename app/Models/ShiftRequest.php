<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Services\Hr\ShiftResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Asking to work a different shift — for good when there is no end date, or
 * just for the days between the two dates.
 *
 * @property int $shift_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property-read Shift $shift
 */
#[Fillable([
    'workspace_id', 'employee_id', 'shift_id', 'starts_on', 'ends_on', 'reason',
    'status', 'reviewer_id', 'reviewed_at', 'review_note',
])]
class ShiftRequest extends HrRequest
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
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
        return 'shift change';
    }

    public function summary(): string
    {
        return "{$this->shift->name} ({$this->shift->hours()}), ".self::span($this->starts_on, $this->ends_on);
    }

    /**
     * A permanent change becomes the person's shift. A temporary one changes
     * nothing on file: {@see ShiftResolver} reads it for the
     * days it covers, so it lapses without anybody having to undo it.
     */
    public function applyApproval(): void
    {
        if ($this->isPermanent()) {
            $this->employee->update(['shift_id' => $this->shift_id]);
        }
    }

    public function auditAction(bool $approved): AuditAction
    {
        return $approved ? AuditAction::ShiftRequestApproved : AuditAction::ShiftRequestRejected;
    }

    /**
     * "from Oct 1, 2026" or "Oct 1 – Oct 5, 2026".
     */
    public static function span(CarbonInterface $from, ?CarbonInterface $to): string
    {
        if ($to === null) {
            return 'from '.$from->format('M j, Y');
        }

        return $from->isSameDay($to)
            ? $from->format('M j, Y')
            : $from->format('M j').' – '.$to->format('M j, Y');
    }
}
