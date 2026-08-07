<?php

namespace App\Http\Requests\Projects;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a task.
 *
 * Every field is `sometimes`, because the detail panel saves one field at a time
 * as it is changed — a picker closing is a save — and a request that only names
 * the assignee must not blank the description by omission.
 */
class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->task()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'type' => ['sometimes', Rule::enum(TaskType::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],

            'parent_task_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],

            // Which version this goes out in. Checked against the project in
            // SaveTask rather than here, the same way an epic and an assignee
            // are — an id from another project resolves to nothing, not to an
            // error somebody has to read.
            'release_id' => ['sometimes', 'nullable', 'integer'],

            'story_points' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            'due_on' => ['sometimes', 'nullable', 'date'],

            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function taskAttributes(): array
    {
        return $this->safe()->only([
            'title', 'description', 'type', 'priority', 'parent_task_id',
            'assignee_id', 'release_id', 'story_points', 'due_on', 'label_ids',
        ]);
    }

    public function task(): Task
    {
        /** @var Task $task */
        $task = $this->route('task');

        return $task;
    }
}
