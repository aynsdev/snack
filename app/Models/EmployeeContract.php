<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property int|null $created_by
 * @property string $title
 * @property EmploymentType $employment_type
 * @property ContractStatus $status
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string|null $salary_amount
 * @property string|null $salary_currency
 * @property string|null $pay_frequency
 * @property string|null $notes
 * @property string|null $file_path
 * @property string|null $file_name
 * @property-read Employee $employee
 */
#[Fillable([
    'employee_id', 'created_by', 'title', 'employment_type', 'status', 'starts_on', 'ends_on',
    'salary_amount', 'salary_currency', 'pay_frequency', 'notes', 'file_path', 'file_name',
])]
class EmployeeContract extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'status' => ContractStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'salary_amount' => 'decimal:2',
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
     * The status to show, which moves an active contract to expired once its
     * end date has passed without anybody having to come back and flip it.
     */
    public function effectiveStatus(): ContractStatus
    {
        if ($this->status === ContractStatus::Active && $this->ends_on?->isPast() && ! $this->ends_on->isToday()) {
            return ContractStatus::Expired;
        }

        return $this->status;
    }
}
