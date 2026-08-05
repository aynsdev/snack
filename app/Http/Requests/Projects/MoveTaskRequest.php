<?php

namespace App\Http\Requests\Projects;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A drop.
 *
 * The client sends *ids*, never positions: which numbers the neighbouring cards
 * currently hold is the server's business, and a client sending them would be
 * sending a copy of a board it painted some seconds ago. See
 * {@see App\Actions\Projects\MoveTask}.
 */
class MoveTaskRequest extends FormRequest
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
            'board_column_id' => ['nullable', 'integer'],
            'sprint_id' => ['nullable', 'integer'],

            // "Put this back in the backlog", which a null sprint_id cannot say
            // — see MoveTask::sprintId() for why the two are different requests.
            'in_backlog' => ['boolean'],

            'before_task_id' => ['nullable', 'integer'],
            'after_task_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function target(): array
    {
        return $this->safe()->only([
            'board_column_id', 'sprint_id', 'in_backlog',
            'before_task_id', 'after_task_id',
        ]);
    }

    public function task(): Task
    {
        /** @var Task $task */
        $task = $this->route('task');

        return $task;
    }
}
