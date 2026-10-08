<?php

namespace App\Http\Requests\Hr;

use App\Enums\EquipmentStatus;
use App\Models\EquipmentRequest;
use Illuminate\Validation\Rule;

/**
 * Approving an equipment request, optionally handing over an available item
 * of the kind asked for in the same step.
 */
class ApproveEquipmentRequestRequest extends ReviewHrRequestRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var EquipmentRequest $request */
        $request = $this->hrRequest();

        return [
            ...parent::rules(),
            'equipment_id' => [
                'nullable', 'integer',
                Rule::exists('equipment', 'id')
                    ->where('workspace_id', $request->workspace_id)
                    ->where('equipment_category_id', $request->equipment_category_id)
                    ->where('status', EquipmentStatus::Available->value),
            ],
        ];
    }
}
