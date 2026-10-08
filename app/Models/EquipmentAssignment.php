<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A piece of equipment handed to somebody, and — once it comes back — when
 * and in what state.
 *
 * @property int $id
 * @property int $equipment_id
 * @property int $employee_id
 * @property Carbon $assigned_on
 * @property int|null $assigned_by
 * @property string|null $condition_out
 * @property Carbon|null $return_requested_at
 * @property Carbon|null $returned_on
 * @property int|null $returned_by
 * @property string|null $condition_in
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Equipment $equipment
 * @property-read Employee $employee
 * @property-read User|null $assigner
 * @property-read User|null $returner
 */
#[Fillable([
    'equipment_id', 'employee_id', 'assigned_on', 'assigned_by', 'condition_out',
    'return_requested_at', 'returned_on', 'returned_by', 'condition_in',
])]
class EquipmentAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_on' => 'date',
            'return_requested_at' => 'datetime',
            'returned_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
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
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('returned_on');
    }

    public function isOpen(): bool
    {
        return $this->returned_on === null;
    }
}
