<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One shift: a geotagged clock-in and, once it ends, a geotagged clock-out.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $employee_id
 * @property Carbon $clock_in_at
 * @property string|null $clock_in_latitude
 * @property string|null $clock_in_longitude
 * @property float|null $clock_in_accuracy
 * @property bool|null $clock_in_within_geofence
 * @property Carbon|null $clock_out_at
 * @property string|null $clock_out_latitude
 * @property string|null $clock_out_longitude
 * @property float|null $clock_out_accuracy
 * @property bool|null $clock_out_within_geofence
 * @property string|null $note
 * @property-read Employee $employee
 */
#[Fillable([
    'workspace_id', 'employee_id',
    'clock_in_at', 'clock_in_latitude', 'clock_in_longitude', 'clock_in_accuracy', 'clock_in_within_geofence',
    'clock_out_at', 'clock_out_latitude', 'clock_out_longitude', 'clock_out_accuracy', 'clock_out_within_geofence',
    'note',
])]
class TimeEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'clock_in_latitude' => 'decimal:7',
            'clock_in_longitude' => 'decimal:7',
            'clock_out_latitude' => 'decimal:7',
            'clock_out_longitude' => 'decimal:7',
            'clock_in_accuracy' => 'float',
            'clock_out_accuracy' => 'float',
            'clock_in_within_geofence' => 'boolean',
            'clock_out_within_geofence' => 'boolean',
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

    public function isOpen(): bool
    {
        return $this->clock_out_at === null;
    }

    /**
     * Minutes worked, counting an open shift up to now.
     */
    public function minutesWorked(): int
    {
        return (int) $this->clock_in_at->diffInMinutes($this->clock_out_at ?? now(), absolute: true);
    }
}
