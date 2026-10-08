<?php

namespace App\Http\Resources\Hr;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A piece of equipment with whoever has it now, and every hand-over before.
 *
 * @mixin Equipment
 */
class EquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->equipment_category_id,
            'category' => $this->category->name,
            'name' => $this->name,
            'tracking_code' => $this->tracking_code,
            'serial_number' => $this->serial_number,
            'purchased_on' => $this->purchased_on?->toDateString(),
            'cost' => $this->cost,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'warranty_expires_on' => $this->warranty_expires_on?->toDateString(),
            'notes' => $this->notes,
            'holder' => $this->openAssignment === null ? null : (new EquipmentAssignmentResource($this->openAssignment))->resolve($request),
            'history' => EquipmentAssignmentResource::collection($this->whenLoaded('assignments'))->resolve($request),
        ];
    }
}
