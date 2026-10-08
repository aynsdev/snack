<?php

namespace App\Http\Requests\Hr;

use App\Models\CompanyPolicy;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

class CompanyPolicyRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        $policy = $this->route('companyPolicy');

        return $policy instanceof CompanyPolicy
            ? ($this->user()?->can('update', $policy) ?? false)
            : ($this->user()?->can('create', [CompanyPolicy::class, $context->workspace()]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:50000'],
            'requires_acknowledgement' => ['boolean'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx'],
            'remove_file' => ['boolean'],
        ];
    }
}
