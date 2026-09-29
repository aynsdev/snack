<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property Carbon $date
 * @property bool $is_recurring
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'name', 'date', 'is_recurring'])]
class Holiday extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_recurring' => 'boolean',
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
     * The date this holiday falls on in the given year.
     */
    public function occurrenceIn(int $year): ?CarbonInterface
    {
        if (! $this->is_recurring) {
            return $this->date->year === $year ? $this->date->copy() : null;
        }

        // 29 February only exists in leap years; skip it otherwise rather than
        // rolling into March.
        if ($this->date->month === 2 && $this->date->day === 29 && ! Carbon::create($year)->isLeapYear()) {
            return null;
        }

        return Carbon::create($year, $this->date->month, $this->date->day)->startOfDay();
    }

    /**
     * The next date on or after today that this holiday falls on.
     */
    public function nextOccurrence(?CarbonInterface $from = null): ?CarbonInterface
    {
        $from = ($from ?? today())->copy()->startOfDay();

        foreach ([$from->year, $from->year + 1, $from->year + 4] as $year) {
            $date = $this->occurrenceIn($year);

            if ($date !== null && $date->gte($from)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeFallingBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orWhere('is_recurring', true));
    }
}
