<?php

namespace App\Http\Requests\Hr;

use App\Enums\WorkType;
use App\Models\WorkTypeRequest;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkTypeRequestRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [WorkTypeRequest::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_type' => ['required', Rule::enum(WorkType::class)],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
