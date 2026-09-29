<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\MoveTask;
use App\Actions\Projects\SaveTask;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Change a card: move it to another column, reassign it, retitle it, and so
 * on — as the token's owner, through the same actions the board uses.
 *
 * Only the fields the assistant sent are touched, the way the board saves one
 * picker at a time: asking to reassign must not blank the description by
 * omission. A status change goes through {@see MoveTask}, the board's own drag,
 * so completion follows the column exactly as it does on screen; everything
 * else goes through {@see SaveTask}, which notifies a new assignee.
 *
 * Gated on TaskPolicy::update — project contributors only, and never on an
 * archived project.
 */
#[Name('update_task')]
#[Title('Update Task')]
#[Description(<<<'TEXT'
Change a task: its status (move it to another column), assignee, priority,
type, title, description, due date, or story points. Only the fields you pass
are changed. Pass the task reference, such as ENG-42.

- `status`: a column name from `get_project`, such as "Done".
- `assignee`: @username of a project member, "me" for the connected person,
  or "none" to unassign. The new assignee is notified.
- `due_on`: a date (2026-10-31), or "none" to clear it.

Only change what the person asked you to change.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class UpdateTaskTool extends SnackTool
{
    use ResolvesProjectWork;

    public function handle(Request $request, SaveTask $saveTask, MoveTask $moveTask): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->validate([
            'task' => ['required', 'string'],
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'string'],
            'assignee' => ['sometimes', 'string'],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'type' => ['sometimes', Rule::enum(TaskType::class)],
            'due_on' => ['sometimes', 'string'],
            'story_points' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $task = $this->task($context, $request->get('task'));

        if ($task === null) {
            return Response::error('There is no task with that reference that this person can see. References look like ENG-42.');
        }

        $allowed = Gate::forUser($context->user)->inspect('update', $task);

        if ($allowed->denied()) {
            return Response::error("This person cannot change {$task->reference()}: {$allowed->message()}");
        }

        $project = $task->project;
        $arguments = $request->all();
        $attributes = array_intersect_key($arguments, array_flip(['title', 'description', 'priority', 'type', 'story_points']));
        $changed = array_keys($attributes);

        if (array_key_exists('assignee', $arguments)) {
            if (strtolower(trim((string) $arguments['assignee'])) === 'none') {
                $attributes['assignee_id'] = null;
            } else {
                $person = $this->projectMember($project, $arguments['assignee'], $context->user);

                if ($person === null) {
                    return Response::error("Nobody on {$project->key} matches [{$arguments['assignee']}]. They must be a member of the project to be assigned. Nothing was changed.");
                }

                $attributes['assignee_id'] = $person->id;
            }

            $changed[] = 'assignee';
        }

        if (array_key_exists('due_on', $arguments)) {
            $due = trim((string) $arguments['due_on']);

            if (strtolower($due) !== 'none' && strtotime($due) === false) {
                return Response::error("[{$due}] is not a date. Use a date like 2026-10-31, or \"none\". Nothing was changed.");
            }

            $attributes['due_on'] = strtolower($due) === 'none' ? null : date('Y-m-d', (int) strtotime($due));
            $changed[] = 'due date';
        }

        $column = null;

        if (array_key_exists('status', $arguments)) {
            $column = $project->columns()->where('name', 'ilike', trim((string) $arguments['status']))->first();

            if ($column === null) {
                return Response::error("{$project->key} has no column called [{$arguments['status']}]. Use `get_project` to see its columns. Nothing was changed.");
            }
        }

        if ($attributes === [] && $column === null) {
            return Response::error('Nothing to change was given.');
        }

        if ($attributes !== []) {
            $task = $saveTask->update($task, $context->user, $attributes);
        }

        if ($column !== null && $column->id !== $task->board_column_id) {
            $task = $moveTask->handle($task, ['board_column_id' => $column->id]);
            $changed[] = "status → {$column->name}";
        }

        return Response::text("Updated {$task->reference()}: ".implode(', ', $changed).'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task reference, such as ENG-42.')->required(),
            'status' => $schema->string()->description('Move to this column, by name.'),
            'assignee' => $schema->string()->description('@username of a project member, "me", or "none".'),
            'priority' => $schema->string()->enum(TaskPriority::values()),
            'type' => $schema->string()->enum(TaskType::values()),
            'title' => $schema->string()->description('A new one-line title.'),
            'description' => $schema->string()->description('A new description. Replaces the old one entirely.'),
            'due_on' => $schema->string()->description('A date such as 2026-10-31, or "none".'),
            'story_points' => $schema->integer()->min(0)->max(999),
        ];
    }
}
