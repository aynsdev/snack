<?php

namespace App\Http\Requests\Hr;

use App\Enums\EmploymentStatus;
use App\Models\DocumentRequest;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequestRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [DocumentRequest::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'allowed_formats' => ['required', 'array', 'min:1'],
            'allowed_formats.*' => ['string', Rule::in(DocumentRequest::FORMATS), 'distinct'],
            'max_size_mb' => ['required', 'integer', 'between:1,20'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => [
                'integer', 'distinct',
                Rule::exists('employees', 'id')
                    ->where('workspace_id', $context->workspace()->id)
                    ->whereNot('status', EmploymentStatus::Terminated->value),
            ],
        ];
    }
}
