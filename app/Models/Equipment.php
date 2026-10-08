<?php

namespace App\Models;

use App\Enums\EquipmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One piece of company equipment — what HR calls an asset. Named equipment in
 * code because "asset" is already the shared file library.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $equipment_category_id
 * @property string $name
 * @property string|null $tracking_code
 * @property string|null $serial_number
 * @property Carbon|null $purchased_on
 * @property string|null $cost
 * @property EquipmentStatus $status
 * @property Carbon|null $warranty_expires_on
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read EquipmentCategory $category
 * @property-read EquipmentAssignment|null $openAssignment
 */
#[Fillable([
    'workspace_id', 'equipment_category_id', 'name', 'tracking_code', 'serial_number',
    'purchased_on', 'cost', 'status', 'warranty_expires_on', 'notes',
])]
class Equipment extends Model
{
    protected $table = 'equipment';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EquipmentStatus::class,
            'purchased_on' => 'date',
            'warranty_expires_on' => 'date',
            'cost' => 'decimal:2',
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
     * @return BelongsTo<EquipmentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }

    /**
     * Every hand-over, newest first: the item's history.
     *
     * @return HasMany<EquipmentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EquipmentAssignment::class)->latest('assigned_on')->latest('id');
    }

    /**
     * Whoever has it now, if anybody.
     *
     * @return HasOne<EquipmentAssignment, $this>
     */
    public function openAssignment(): HasOne
    {
        return $this->hasOne(EquipmentAssignment::class)->whereNull('returned_on');
    }
}
