<?php

namespace App\Http\Requests\Hr;

use App\Enums\EmploymentStatus;
use App\Models\Equipment;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Equipment $equipment */
        $equipment = $this->route('equipment');

        return $this->user()?->can('update', $equipment) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employees', 'id')
                    ->where('workspace_id', $context->workspace()->id)
                    ->whereNot('status', EmploymentStatus::Terminated->value),
            ],
            'assigned_on' => ['nullable', 'date'],
            'condition' => ['nullable', 'string', 'max:500'],
        ];
    }
}
