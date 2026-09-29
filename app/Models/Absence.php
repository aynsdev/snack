<?php

namespace App\Models;

use App\Enums\AbsenceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A day somebody was away without approved leave.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $employee_id
 * @property int|null $recorded_by
 * @property Carbon $date
 * @property AbsenceType $type
 * @property string|null $note
 * @property-read Employee $employee
 * @property-read User|null $recorder
 */
#[Fillable(['workspace_id', 'employee_id', 'recorded_by', 'date', 'type', 'note'])]
class Absence extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AbsenceType::class,
            'date' => 'date',
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
