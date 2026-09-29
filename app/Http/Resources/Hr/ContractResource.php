<?php

namespace App\Http\Resources\Hr;

use App\Models\EmployeeContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeContract
 */
class ContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->effectiveStatus();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'employment_type' => $this->employment_type->value,
            'employment_type_label' => $this->employment_type->label(),
            'status' => $this->status->value,
            'effective_status' => $status->value,
            'effective_status_label' => $status->label(),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'salary_amount' => $this->salary_amount,
            'salary_currency' => $this->salary_currency,
            'pay_frequency' => $this->pay_frequency,
            'notes' => $this->notes,
            'file_name' => $this->file_name,
            'has_file' => $this->file_path !== null,
        ];
    }
}
