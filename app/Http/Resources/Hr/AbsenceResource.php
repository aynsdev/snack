<?php

namespace App\Http\Resources\Hr;

use App\Models\Absence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Absence
 */
class AbsenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'note' => $this->note,
            'recorded_by' => $this->recorder?->name,
        ];
    }
}
