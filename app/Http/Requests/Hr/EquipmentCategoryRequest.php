<?php

namespace App\Http\Requests\Hr;

use App\Models\EquipmentCategory;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentCategoryRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        $category = $this->route('equipmentCategory');

        return $category instanceof EquipmentCategory
            ? ($this->user()?->can('update', $category) ?? false)
            : ($this->user()?->can('create', [EquipmentCategory::class, $context->workspace()]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        $category = $this->route('equipmentCategory');

        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('equipment_categories')
                    ->where('workspace_id', $context->workspace()->id)
                    ->ignore($category instanceof EquipmentCategory ? $category->id : null),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
