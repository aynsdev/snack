<?php

namespace App\Models;

use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A kind of leave a workspace offers, and how many days a year of it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $color
 * @property string|null $days_per_year
 * @property bool $is_paid
 * @property Carbon|null $archived_at
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'name', 'color', 'days_per_year', 'is_paid', 'archived_at'])]
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    /**
     * What a workspace starts with the first time anybody opens HR.
     *
     * @var array<int, array{name: string, color: string, days_per_year: int|null, is_paid: bool}>
     */
    public const array DEFAULTS = [
        ['name' => 'Vacation', 'color' => 'sky', 'days_per_year' => 15, 'is_paid' => true],
        ['name' => 'Sick', 'color' => 'rose', 'days_per_year' => 10, 'is_paid' => true],
        ['name' => 'Personal', 'color' => 'amber', 'days_per_year' => 3, 'is_paid' => true],
        ['name' => 'Unpaid', 'color' => 'slate', 'days_per_year' => null, 'is_paid' => false],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days_per_year' => 'decimal:1',
            'is_paid' => 'boolean',
            'archived_at' => 'datetime',
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
     * @return HasMany<LeaveRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Give a workspace the default leave types the first time HR is opened,
     * so the leave form is never empty on day one.
     */
    public static function seedDefaultsFor(Workspace $workspace): void
    {
        if ($workspace->leaveTypes()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $attributes) {
            $workspace->leaveTypes()->firstOrCreate(['name' => $attributes['name']], $attributes);
        }
    }

    public function allowance(): ?float
    {
        return $this->days_per_year === null ? null : (float) $this->days_per_year;
    }
}
