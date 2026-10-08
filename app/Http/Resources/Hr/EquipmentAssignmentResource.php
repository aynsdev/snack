<?php

namespace App\Http\Resources\Hr;

use App\Models\EquipmentAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EquipmentAssignment
 */
class EquipmentAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'equipment' => $this->whenLoaded('equipment', fn (): array => [
                'id' => $this->equipment->id,
                'name' => $this->equipment->name,
                'tracking_code' => $this->equipment->tracking_code,
                'serial_number' => $this->equipment->serial_number,
                'category' => $this->equipment->category->name,
            ]),
            'employee' => $this->whenLoaded('employee', fn (): array => [
                'id' => $this->employee->id,
                'name' => $this->employee->user->name,
                'initials' => $this->employee->user->initials(),
                'avatar_url' => $this->employee->user->avatarUrl(),
            ]),
            'assigned_on' => $this->assigned_on->toDateString(),
            'assigned_by' => $this->assigner?->name,
            'condition_out' => $this->condition_out,
            'return_requested_at' => $this->return_requested_at?->toIso8601String(),
            'returned_on' => $this->returned_on?->toDateString(),
            'returned_by' => $this->returner?->name,
            'condition_in' => $this->condition_in,
            'can' => [
                'request_return' => $user?->can('requestReturn', $this->resource) ?? false,
                'take_back' => $user?->can('takeBack', $this->resource) ?? false,
            ],
        ];
    }
}
