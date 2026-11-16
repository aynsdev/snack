<?php

namespace App\Http\Requests\Projects;

use App\Actions\Projects\BulkUpdateTasks;
use App\Enums\TaskAgentIntent;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The board's bulk action bar: several selected cards, one change.
 *
 * Ids are checked for shape here and for belonging — every card on this
 * project, the sprint open and this project's, the assignee a member — in
 * {@see BulkUpdateTasks}, for the reason {@see StoreTaskRequest} gives.
 */
class BulkUpdateTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Task::class, $this->project()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'task_ids' => ['required', 'array', 'min:1', 'max:'.BulkUpdateTasks::MAX_TASKS],
            'task_ids.*' => ['integer', 'distinct'],

            // Same pair as a drop: "the backlog" is its own flag, because a
            // null sprint cannot tell it apart from "no sprint change".
            'sprint_id' => ['sometimes', 'nullable', 'integer'],
            'in_backlog' => ['sometimes', 'boolean'],

            // Only handing cards to an agent: starting and stopping one are
            // the assignee's own decisions, made per card in its panel.
            'agent' => ['sometimes', Rule::in([TaskAgentIntent::Assign->value])],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->placement() === null && $this->agent() === null) {
                    $validator->errors()->add('task_ids', 'Choose a sprint or an agent for the selected tasks.');
                }
            },
        ];
    }

    /**
     * @return array<int, int>
     */
    public function taskIds(): array
    {
        return array_map('intval', $this->array('task_ids'));
    }

    /**
     * @return array{sprint_id?: int|null, in_backlog?: bool}|null
     */
    public function placement(): ?array
    {
        if ($this->boolean('in_backlog')) {
            return ['in_backlog' => true];
        }

        return $this->filled('sprint_id') ? ['sprint_id' => $this->integer('sprint_id')] : null;
    }

    /**
     * @return array{assignee_id: int|null}|null
     */
    public function agent(): ?array
    {
        return $this->has('agent')
            ? ['assignee_id' => $this->integer('assignee_id') ?: null]
            : null;
    }

    public function project(): Project
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $project;
    }
}
