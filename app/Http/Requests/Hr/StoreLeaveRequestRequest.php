<?php

namespace App\Http\Requests\Hr;

use App\Models\LeaveRequest;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [LeaveRequest::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'leave_type_id' => [
                'required', 'integer',
                Rule::exists('leave_types', 'id')
                    ->where('workspace_id', $context->workspace()->id)
                    ->whereNull('archived_at'),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on', 'before_or_equal:'.now()->addYears(2)->toDateString()],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
