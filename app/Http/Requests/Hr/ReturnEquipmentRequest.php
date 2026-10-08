<?php

namespace App\Http\Requests\Hr;

use App\Enums\EquipmentStatus;
use App\Models\EquipmentAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReturnEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var EquipmentAssignment $assignment */
        $assignment = $this->route('equipmentAssignment');

        return $this->user()?->can('takeBack', $assignment) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_map(fn (EquipmentStatus $status): string => $status->value, EquipmentStatus::settable()))],
            'returned_on' => ['nullable', 'date'],
            'condition' => ['nullable', 'string', 'max:500'],
        ];
    }
}
