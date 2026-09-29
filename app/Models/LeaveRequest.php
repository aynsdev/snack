<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use Carbon\CarbonInterface;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int|null $reviewer_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $days
 * @property string|null $reason
 * @property LeaveStatus $status
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property-read Employee $employee
 * @property-read LeaveType $leaveType
 * @property-read User|null $reviewer
 */
#[Fillable([
    'workspace_id', 'employee_id', 'leave_type_id', 'reviewer_id', 'starts_on', 'ends_on',
    'days', 'reason', 'status', 'reviewed_at', 'review_note',
])]
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeaveStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days' => 'decimal:1',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * Requests whose dates touch the given range.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', LeaveStatus::Approved);
    }
}
