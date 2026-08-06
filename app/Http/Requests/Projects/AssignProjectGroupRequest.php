<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filing one project under one heading, or under none.
 */
class AssignProjectGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->project()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * `present` with `nullable`: the key must be in the payload, and its
             * value may be null. Null is the whole of "take it out of its
             * group" — ungrouped is not a second state to be reached by a
             * second endpoint, it is the absence of a group.
             *
             * Scoped to the project's own workspace rather than trusted, so an
             * id from a select the client rendered cannot file this project onto
             * another tenant's shelf.
             */
            'project_group_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('project_groups', 'id')
                    ->where('workspace_id', $this->project()->workspace_id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_group_id.exists' => 'That group is not part of this workspace.',
        ];
    }

    private function project(): Project
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $project;
    }
}
