<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [Project::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:80'],

            /*
             * Optional: left blank, one is derived from the name — see
             * SlugGenerator::forProjectKey. When it is given it has to be usable
             * as the prefix of a reference people type and read out loud, so
             * letters and digits only, and unique in the workspace because two
             * projects answering to PWA-1 is a reference that means nothing.
             */
            'key' => [
                'nullable',
                'string',
                'min:2',
                'max:10',
                'regex:/^[A-Za-z][A-Za-z0-9]*$/',
                Rule::unique('projects', 'key')->where('workspace_id', $context->workspace()->id),
            ],

            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:20'],
            'uses_sprints' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'A project key starts with a letter and contains only letters and numbers.',
            'key.unique' => 'Another project in this workspace already uses that key.',
        ];
    }
}
