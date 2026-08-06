<?php

namespace App\Http\Requests\Projects;

use App\Models\ProjectGroup;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectGroupRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [ProjectGroup::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            /*
             * Unique per workspace, case-insensitively — the column is citext,
             * and two shelves both called "Platform" would be a list nobody can
             * read and a menu nobody can choose from.
             */
            'name' => [
                'required',
                'string',
                'min:1',
                'max:60',
                Rule::unique('project_groups', 'name')->where('workspace_id', $context->workspace()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'This workspace already has a group with that name.',
        ];
    }
}
