<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Enums\LeaveStatus;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A workspace member seen from HR: their job, their paperwork, their time.
 *
 * Every human workspace member has one — see {@see WorkspaceMember::booted()} —
 * and the row outlives the membership, so a person removed from the workspace
 * keeps their employment history.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property int|null $workspace_member_id
 * @property int|null $manager_id
 * @property string|null $employee_number
 * @property string|null $job_title
 * @property string|null $department
 * @property EmploymentType $employment_type
 * @property EmploymentStatus $status
 * @property Carbon|null $hired_on
 * @property Carbon|null $terminated_on
 * @property Carbon|null $birth_date
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User $user
 * @property-read WorkspaceMember|null $membership
 * @property-read Employee|null $manager
 */
#[Fillable([
    'workspace_id', 'user_id', 'workspace_member_id', 'manager_id', 'employee_number',
    'job_title', 'department', 'employment_type', 'status', 'hired_on', 'terminated_on',
    'birth_date', 'phone', 'address', 'emergency_contact_name', 'emergency_contact_phone',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'status' => EmploymentStatus::class,
            'hired_on' => 'date',
            'terminated_on' => 'date',
            'birth_date' => 'date',
        ];
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WorkspaceMember, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(WorkspaceMember::class, 'workspace_member_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /**
     * @return HasMany<EmploymentHistory, $this>
     */
    public function employmentHistories(): HasMany
    {
        return $this->hasMany(EmploymentHistory::class);
    }

    /**
     * @return HasMany<EmployeeContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class);
    }

    /**
     * @return HasMany<EmployeeRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(EmployeeRecord::class);
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * @return HasMany<Absence, $this>
     */
    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * The shift the person is currently clocked into, if any.
     *
     * @return HasOne<TimeEntry, $this>
     */
    public function openTimeEntry(): HasOne
    {
        return $this->hasOne(TimeEntry::class)->whereNull('clock_out_at');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeEmployed(Builder $query): void
    {
        $query->where('status', '!=', EmploymentStatus::Terminated);
    }

    // -- Leave ---------------------------------------------------------------

    /**
     * Days of each leave type already taken or booked in the given year.
     *
     * Pending requests count too: a balance that ignored them would let
     * somebody book the same allowance twice before either was approved.
     *
     * @return array<int, float> keyed by leave type id
     */
    public function leaveDaysUsed(int $year): array
    {
        return $this->leaveRequests()
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->whereYear('starts_on', $year)
            ->groupBy('leave_type_id')
            ->selectRaw('leave_type_id, sum(days) as used')
            ->pluck('used', 'leave_type_id')
            ->map(fn (mixed $used): float => (float) $used)
            ->all();
    }
}
