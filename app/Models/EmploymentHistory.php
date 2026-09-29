<?php

namespace App\Models;

use App\Enums\EmploymentChange;
use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change in somebody's employment — hired, promoted, moved, let go.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $recorded_by
 * @property EmploymentChange $change
 * @property Carbon $effective_on
 * @property string|null $job_title
 * @property string|null $department
 * @property EmploymentType|null $employment_type
 * @property string|null $notes
 * @property-read Employee $employee
 * @property-read User|null $recorder
 */
#[Fillable(['employee_id', 'recorded_by', 'change', 'effective_on', 'job_title', 'department', 'employment_type', 'notes'])]
class EmploymentHistory extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'change' => EmploymentChange::class,
            'employment_type' => EmploymentType::class,
            'effective_on' => 'date',
        ];
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
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
