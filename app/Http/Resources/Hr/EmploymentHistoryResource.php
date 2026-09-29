<?php

namespace App\Http\Resources\Hr;

use App\Models\EmploymentHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmploymentHistory
 */
class EmploymentHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'change' => $this->change->value,
            'change_label' => $this->change->label(),
            'effective_on' => $this->effective_on->toDateString(),
            'job_title' => $this->job_title,
            'department' => $this->department,
            'employment_type_label' => $this->employment_type?->label(),
            'notes' => $this->notes,
            'recorded_by' => $this->recorder?->name,
        ];
    }
}
