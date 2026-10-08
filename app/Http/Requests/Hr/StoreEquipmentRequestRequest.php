<?php

namespace App\Http\Requests\Hr;

use App\Models\EquipmentRequest;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEquipmentRequestRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [EquipmentRequest::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'equipment_category_id' => ['required', 'integer', Rule::exists('equipment_categories', 'id')->where('workspace_id', $context->workspace()->id)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
