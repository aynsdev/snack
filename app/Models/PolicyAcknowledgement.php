<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An employee confirming they have read a {@see CompanyPolicy}.
 *
 * @property int $id
 * @property int $company_policy_id
 * @property int $employee_id
 * @property Carbon $acknowledged_at
 * @property-read CompanyPolicy $policy
 * @property-read Employee $employee
 */
#[Fillable(['company_policy_id', 'employee_id', 'acknowledged_at'])]
class PolicyAcknowledgement extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CompanyPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(CompanyPolicy::class, 'company_policy_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
