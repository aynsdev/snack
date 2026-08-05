<?php

namespace App\Http\Requests\Projects;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filing a task.
 *
 * The id-bearing fields — column, sprint, epic, assignee, labels — are checked
 * for *shape* here and for *belonging* in {@see App\Actions\Projects\SaveTask},
 * which re-resolves each one within the project. An `exists` rule cannot express
 * "in this project", and one that only checked the table would happily accept a
 * column belonging to another team's board.
 */
class StoreTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'type' => ['required', Rule::enum(TaskType::class)],
            'priority' => ['required', Rule::enum(TaskPriority::class)],

            'board_column_id' => ['nullable', 'integer'],
            'sprint_id' => ['nullable', 'integer'],
            'parent_task_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],

            'story_points' => ['nullable', 'integer', 'min:0', 'max:999'],
            'due_on' => ['nullable', 'date'],

            'label_ids' => ['array'],
            'label_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function taskAttributes(): array
    {
        return $this->safe()->only([
            'title', 'description', 'type', 'priority', 'board_column_id',
            'sprint_id', 'parent_task_id', 'assignee_id', 'story_points',
            'due_on', 'label_ids',
        ]);
    }

    public function project(): Project
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $project;
    }
}
