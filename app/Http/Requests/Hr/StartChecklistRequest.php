<?php

namespace App\Http\Requests\Hr;

use App\Enums\EmploymentStatus;
use App\Models\EmployeeChecklist;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartChecklistRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [EmployeeChecklist::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        $workspaceId = $context->workspace()->id;

        return [
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employees', 'id')->where('workspace_id', $workspaceId)->whereNot('status', EmploymentStatus::Terminated->value),
            ],
            'checklist_template_id' => ['required', 'integer', Rule::exists('checklist_templates', 'id')->where('workspace_id', $workspaceId)],
            'starts_on' => ['required', 'date'],
            'target_on' => ['nullable', 'date'],
        ];
    }
}
