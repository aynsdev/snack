<?php

namespace App\Http\Resources\Hr;

use App\Models\EmployeeRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeRecord
 */
class EmployeeRecordResource extends JsonResource
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
            'title' => $this->title,
            'body' => $this->body,
            'recorded_on' => $this->recorded_on->toDateString(),
            'recorded_by' => $this->recorder?->name,
            'file_name' => $this->file_name,
            'has_file' => $this->file_path !== null,
        ];
    }
}
