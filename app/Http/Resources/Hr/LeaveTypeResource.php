<?php

namespace App\Http\Resources\Hr;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveType
 */
class LeaveTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'days_per_year' => $this->allowance(),
            'is_paid' => $this->is_paid,
            'is_archived' => $this->archived_at !== null,
        ];
    }
}
