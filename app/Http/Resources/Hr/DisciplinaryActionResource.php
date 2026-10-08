<?php

namespace App\Http\Resources\Hr;

use App\Models\DisciplinaryAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DisciplinaryAction
 */
class DisciplinaryActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'description' => $this->description,
            'starts_on' => $this->starts_on->toDateString(),
            'duration_days' => $this->duration_days,
            'ends_on' => $this->endsOn()?->toDateString(),
            'issued_by' => $this->issuer?->name,
            'file_name' => $this->file_name,
            'has_file' => $this->file_path !== null,
        ];
    }
}
