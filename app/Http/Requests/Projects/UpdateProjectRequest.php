<?php

namespace App\Http\Requests\Projects;

use App\Actions\Projects\UpdateProject;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\GitHubRepository;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
{
    /**
     * A lead's right, unless the save only sets the project's agents up — the
     * channel, the columns and the repository — which any member may do; see
     * {@see ProjectPolicy::configureAgents()}. Returned as a response so the
     * person refused is told why.
     */
    public function authorize(): Response|bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $sent = array_intersect(array_keys($this->all()), array_keys($this->rules()));
        $ability = array_diff($sent, Project::AGENT_CONFIGURATION) === [] && $sent !== []
            ? 'configureAgents'
            : 'update';

        return Gate::forUser($user)->inspect($ability, $this->project());
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

            // Stored normalised by UpdateProject; see GitHubRepository.
            'repository_url' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && GitHubRepository::normalize($value) === null) {
                        $fail('Use a GitHub link like https://github.com/owner/repo or git@github.com:owner/repo.git.');
                    }
                },
            ],
            'repository_base_branch' => [
                'sometimes',
                'nullable',
                'string',
                'max:200',
                function (string $attribute, mixed $value, Closure $fail) use ($project): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (! GitHubRepository::isSafeBranch($value)) {
                        $fail('That is not a branch name agents can use.');
                    }

                    $repository = $this->exists('repository_url') ? $this->input('repository_url') : $project->repository_url;

                    if (! is_string($repository) || trim($repository) === '') {
                        $fail('Set the repository before choosing a base branch.');
                    }
                },
            ],
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

            /*
             * Where this project's agents talk, and which of its own columns
             * they move cards into. The channel is checked in {@see after()},
             * where who is asking is known; the columns only have to be this
             * project's.
             */
            'agent_channel_id' => [
                'sometimes',
                'nullable',
                'integer:strict',
                Rule::exists('channels', 'id')->where('workspace_id', $project->workspace_id),
            ],
            'agent_in_progress_column_id' => [
                'sometimes',
                'nullable',
                'integer:strict',
                Rule::exists('board_columns', 'id')->where('project_id', $project->id),
            ],
            'agent_review_column_id' => [
                'sometimes',
                'nullable',
                'integer:strict',
                Rule::exists('board_columns', 'id')->where('project_id', $project->id),
            ],
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

            /*
             * An agent channel is where a project's ticket conversations are
             * sent out to the agent dispatcher, so choosing one is choosing what
             * leaves Snack. It must be a live, non-announcement channel in this
             * workspace that the person choosing it can see — otherwise anybody
             * could export a private channel they were never in by typing its
             * id. Keeping the channel already set is always allowed, so someone
             * who cannot see it can still change the columns.
             */
            function (Validator $validator): void {
                $channelId = $this->input('agent_channel_id');
                $project = $this->project();

                if ($channelId === null || $channelId === $project->agent_channel_id) {
                    return;
                }

                /** @var User $user */
                $user = $this->user();

                if (! is_int($channelId) || ! $project->acceptsAgentChannelFrom($user, $channelId)) {
                    $validator->errors()->add('agent_channel_id', UpdateProject::AGENT_CHANNEL_REFUSED);
                }
            },

            /*
             * The two agent columns must differ, or an agent opening a pull
             * request would move the card to where it already is. Checked
             * against the stored value for whichever column was not sent,
             * because the settings page saves one picker at a time.
             */
            function (Validator $validator): void {
                $project = $this->project();

                $inProgress = $this->exists('agent_in_progress_column_id')
                    ? $this->input('agent_in_progress_column_id')
                    : $project->agent_in_progress_column_id;
                $review = $this->exists('agent_review_column_id')
                    ? $this->input('agent_review_column_id')
                    : $project->agent_review_column_id;

                if (! is_numeric($inProgress) || ! is_numeric($review) || (int) $inProgress !== (int) $review) {
                    return;
                }

                $field = $this->exists('agent_review_column_id') ? 'agent_review_column_id' : 'agent_in_progress_column_id';

                $validator->errors()->add($field, UpdateProject::AGENT_COLUMNS_SAME);
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
