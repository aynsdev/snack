<?php

namespace App\Models;

use App\Enums\DisciplinaryActionType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A warning, suspension, or dismissal issued to an employee. Unlike HR's
 * records, the employee sees these on their own file.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $employee_id
 * @property int|null $issued_by
 * @property DisciplinaryActionType $type
 * @property string $description
 * @property Carbon $starts_on
 * @property int|null $duration_days
 * @property string|null $file_path
 * @property string|null $file_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read User|null $issuer
 */
#[Fillable([
    'workspace_id', 'employee_id', 'issued_by', 'type', 'description', 'starts_on',
    'duration_days', 'file_path', 'file_name',
])]
class DisciplinaryAction extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DisciplinaryActionType::class,
            'starts_on' => 'date',
            'duration_days' => 'integer',
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
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * The last day of a suspension; null for actions with no duration.
     */
    public function endsOn(): ?CarbonInterface
    {
        return $this->duration_days === null ? null : $this->starts_on->addDays($this->duration_days - 1);
    }
}
