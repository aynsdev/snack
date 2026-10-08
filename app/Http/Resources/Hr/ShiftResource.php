<?php

namespace App\Http\Resources\Hr;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_at' => substr($this->starts_at, 0, 5),
            'ends_at' => substr($this->ends_at, 0, 5),
            'hours' => $this->hours(),
            'grace_minutes' => $this->grace_minutes,
            'days' => array_map('intval', $this->days),
            'is_default' => $this->is_default,
        ];
    }
}
