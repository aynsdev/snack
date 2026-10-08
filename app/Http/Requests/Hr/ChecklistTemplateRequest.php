<?php

namespace App\Http\Requests\Hr;

use App\Enums\ChecklistAssignee;
use App\Enums\ChecklistKind;
use App\Models\ChecklistTemplate;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A template and all its steps, saved together; the steps sent replace the
 * ones on file.
 */
class ChecklistTemplateRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        $template = $this->route('checklistTemplate');

        return $template instanceof ChecklistTemplate
            ? ($this->user()?->can('update', $template) ?? false)
            : ($this->user()?->can('create', [ChecklistTemplate::class, $context->workspace()]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => [$this->route('checklistTemplate') ? 'prohibited' : 'required', Rule::enum(ChecklistKind::class)],
            'name' => ['required', 'string', 'max:120'],
            'is_default' => ['boolean'],
            'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.title' => ['required', 'string', 'max:160'],
            'tasks.*.description' => ['nullable', 'string', 'max:2000'],
            'tasks.*.assignee_role' => ['required', Rule::enum(ChecklistAssignee::class)],
            'tasks.*.due_offset_days' => ['required', 'integer', 'between:-365,365'],
        ];
    }
}
