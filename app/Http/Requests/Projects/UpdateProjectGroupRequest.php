<?php

namespace App\Http\Requests\Projects;

use App\Models\ProjectGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->group()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $group = $this->group();

        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:60',
                Rule::unique('project_groups', 'name')
                    ->where('workspace_id', $group->workspace_id)
                    ->ignore($group->id),
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

    private function group(): ProjectGroup
    {
        /** @var ProjectGroup $group */
        $group = $this->route('projectGroup');

        return $group;
    }
}
