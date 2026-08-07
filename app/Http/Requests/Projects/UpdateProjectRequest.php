<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
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
        $project = $this->project();

        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:80'],

            'key' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:10',
                'regex:/^[A-Za-z][A-Za-z0-9]*$/',
                Rule::unique('projects', 'key')
                    ->where('workspace_id', $project->workspace_id)
                    ->ignore($project->id),
            ],

            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['sometimes', 'string', 'max:20'],
            'uses_sprints' => ['sometimes', 'boolean'],

            /*
             * Where this project's references carry on from — the 1499 in
             * PWA-1499. Moved rather than reset: a team arriving from another
             * tracker sets it past the numbers they already quoted, so nothing
             * they wrote down means two different things. The floor is checked
             * in {@see after()}, where the numbers already handed out are known.
             */
            'next_task_number' => ['sometimes', 'integer', 'min:1', 'max:999999999'],

            /*
             * Which fields the cards on this board draw. Keyed by field name and
             * bounded to the fields that exist, so an unknown key is a
             * validation failure here rather than a stray entry stored in the
             * jsonb column for ever.
             */
            'card_fields' => ['sometimes', 'array'],
            'card_fields.*' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            /*
             * The counter may not be wound back over a number already in use.
             *
             * `(project_id, number)` is unique, so a counter behind the highest
             * reference does not fail here — it fails later, on somebody's
             * perfectly ordinary click, once the counter catches up. Refused at
             * the point it is set, naming the reference in the way.
             */
            function (Validator $validator): void {
                $next = $this->input('next_task_number');

                if ($next === null) {
                    return;
                }

                $highest = (int) $this->project()->tasks()->max('number');

                if ($highest > 0 && (int) $next <= $highest) {
                    $validator->errors()->add(
                        'next_task_number',
                        "This project has already issued {$this->project()->reference($highest)}, so the next number must be at least ".($highest + 1).'.',
                    );
                }
            },

            function (Validator $validator): void {
                $fields = $this->input('card_fields');

                if (! is_array($fields)) {
                    return;
                }

                $unknown = array_diff(array_keys($fields), array_keys(Project::CARD_FIELD_DEFAULTS));

                if ($unknown !== []) {
                    $validator->errors()->add('card_fields', 'Unknown card field: '.implode(', ', $unknown).'.');
                }
            },
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

    private function project(): Project
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $project;
    }
}
