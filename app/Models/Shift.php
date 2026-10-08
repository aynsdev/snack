<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Working hours people are scheduled on, measured against when they clock.
 *
 * Times are wall-clock times in the app's timezone. A shift whose end is not
 * after its start runs past midnight and ends the following day.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $starts_at
 * @property string $ends_at
 * @property int $grace_minutes
 * @property array<int, int> $days
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'name', 'starts_at', 'ends_at', 'grace_minutes', 'days', 'is_default'])]
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grace_minutes' => 'integer',
            'days' => 'array',
            'is_default' => 'boolean',
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
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /**
     * Whether the shift is worked on the given date's weekday.
     */
    public function worksOn(CarbonInterface $date): bool
    {
        return in_array($date->isoWeekday(), array_map('intval', $this->days), true);
    }

    /**
     * The shift's start and end on the given date, in the app's timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowOn(CarbonInterface $date): array
    {
        $day = CarbonImmutable::parse($date->format('Y-m-d'), config('app.timezone'));

        $start = $day->setTimeFromTimeString($this->starts_at);
        $end = $day->setTimeFromTimeString($this->ends_at);

        return [$start, $end->lte($start) ? $end->addDay() : $end];
    }

    /**
     * "09:00 – 18:00", without seconds.
     */
    public function hours(): string
    {
        return substr($this->starts_at, 0, 5).' – '.substr($this->ends_at, 0, 5);
    }
}
