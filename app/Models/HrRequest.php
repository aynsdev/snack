<?php

namespace App\Models;

use App\Actions\Hr\NotifyHrRequest;
use App\Actions\Hr\ReviewHrRequest;
use App\Enums\AuditAction;
use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something an employee asks HR for and HR approves or rejects: a shift change,
 * a work-type change, an attendance correction.
 *
 * Each kind says what it is, how to describe one, and what approving it does;
 * reviewing, notifying, and auditing are shared — see
 * {@see ReviewHrRequest} and {@see NotifyHrRequest}.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $employee_id
 * @property RequestStatus $status
 * @property int|null $reviewer_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Employee $employee
 * @property-read User|null $reviewer
 */
abstract class HrRequest extends Model
{
    /**
     * A short noun for the kind of request: "shift change".
     */
    abstract public function kind(): string;

    /**
     * What is being asked for, in a sentence fragment: "Night shift (22:00 – 06:00) from Oct 1".
     */
    abstract public function summary(): string;

    /**
     * Makes the approved request take effect. Runs inside the review's
     * transaction, after the status has been set.
     */
    abstract public function applyApproval(): void;

    abstract public function auditAction(bool $approved): AuditAction;

    /**
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'employee' => $this->employee->user->name,
            'request' => $this->summary(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
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
        $query->where('status', RequestStatus::Pending);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', RequestStatus::Approved);
    }
}
