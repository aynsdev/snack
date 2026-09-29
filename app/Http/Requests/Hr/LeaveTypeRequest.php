<?php

namespace App\Http\Requests\Hr;

use App\Models\LeaveType;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveTypeRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        $leaveType = $this->route('leaveType');

        return $leaveType instanceof LeaveType
            ? ($this->user()?->can('update', $leaveType) ?? false)
            : ($this->user()?->can('create', [LeaveType::class, $context->workspace()]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        $leaveType = $this->route('leaveType');

        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('leave_types')
                    ->where('workspace_id', $context->workspace()->id)
                    ->ignore($leaveType instanceof LeaveType ? $leaveType->id : null),
            ],
            'color' => ['required', Rule::in(['sky', 'rose', 'amber', 'emerald', 'violet', 'slate'])],
            'days_per_year' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'is_paid' => ['boolean'],
            'is_archived' => ['boolean'],
        ];
    }
}
