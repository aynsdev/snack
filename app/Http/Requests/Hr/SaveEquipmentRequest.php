<?php

namespace App\Http\Requests\Hr;

use App\Enums\EquipmentStatus;
use App\Models\Equipment;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or editing a piece of equipment. Its status can be set by hand only
 * among the statuses that do not involve a holder; handing it out and taking
 * it back set the rest.
 */
class SaveEquipmentRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        $equipment = $this->route('equipment');

        return $equipment instanceof Equipment
            ? ($this->user()?->can('update', $equipment) ?? false)
            : ($this->user()?->can('create', [Equipment::class, $context->workspace()]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        $workspaceId = $context->workspace()->id;
        $equipment = $this->route('equipment');
        $assigned = $equipment instanceof Equipment && $equipment->status === EquipmentStatus::Assigned;

        return [
            'equipment_category_id' => ['required', 'integer', Rule::exists('equipment_categories', 'id')->where('workspace_id', $workspaceId)],
            'name' => ['required', 'string', 'max:160'],
            'tracking_code' => [
                'nullable', 'string', 'max:60',
                Rule::unique('equipment')->where('workspace_id', $workspaceId)->ignore($equipment instanceof Equipment ? $equipment->id : null),
            ],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'purchased_on' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'warranty_expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => $assigned
                ? ['prohibited']
                : ['required', Rule::in(array_map(fn (EquipmentStatus $status): string => $status->value, EquipmentStatus::settable()))],
        ];
    }
}
