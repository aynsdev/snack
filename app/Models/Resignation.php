<?php

namespace App\Models;

use App\Enums\ResignationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An employee giving notice. Accepting it starts their offboarding.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $employee_id
 * @property string $reason
 * @property Carbon $last_day_on
 * @property ResignationStatus $status
 * @property int|null $reviewer_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Employee $employee
 * @property-read User|null $reviewer
 */
#[Fillable(['workspace_id', 'employee_id', 'reason', 'last_day_on', 'status', 'reviewer_id', 'reviewed_at', 'review_note'])]
class Resignation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ResignationStatus::class,
            'last_day_on' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ResignationStatus::Pending);
    }
}
