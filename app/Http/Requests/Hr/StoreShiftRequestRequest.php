<?php

namespace App\Http\Requests\Hr;

use App\Models\ShiftRequest;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequestRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [ShiftRequest::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')->where('workspace_id', $context->workspace()->id)],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
