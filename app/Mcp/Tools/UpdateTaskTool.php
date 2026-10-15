<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\MoveTask;
use App\Actions\Projects\SaveTask;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Mcp\Tools\Concerns\PlacesTasks;
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
Change a task: its status (move it to another column), sprint, release,
parent task, labels, assignee, priority, type, title, description, due date,
or story points. Pass `parent` to make it a subtask of another task, or
`parent: "none"` to take it out from under its parent. Only the fields you pass are changed. Pass the task
reference, such as ENG-42.

- `status`: a column name from `get_project`, such as "Done".
- `sprint`: "active" for the running sprint, an open sprint's name, or
  "backlog". See `list_sprints`. Unfinished subtasks move with
  their parent.
- `release`: a release name such as "v3.2.1", or "none". See `list_releases`.
- `parent`: the reference of a task in the same project, or "none". An
  epic cannot have a parent, a subtask must have one (a story, task, or
  bug), and a task cannot go under its own subtask.
- `add_labels` / `remove_labels`: label names the project already has.
- `assignee`: @username of a project member, "me" for the connected person,
  or "none" to unassign. The new assignee is notified.
- `due_on`: a date (2026-10-31), or "none" to clear it.

A name that matches nothing is refused with the valid options, and nothing
is changed. Only change what the person asked you to change.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class UpdateTaskTool extends SnackTool
{
    use PlacesTasks;
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
            'sprint' => ['sometimes', 'string'],
            'release' => ['sometimes', 'string'],
            'parent' => ['sometimes', 'string'],
            'add_labels' => ['sometimes', 'array'],
            'add_labels.*' => ['string'],
            'remove_labels' => ['sometimes', 'array'],
            'remove_labels.*' => ['string'],
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
            $due = $this->dueDate($arguments['due_on']);

            if ($due === false) {
                return Response::error("[{$arguments['due_on']}] is not a date. Use a date like 2026-10-31, or \"none\". Nothing was changed.");
            }

            $attributes['due_on'] = $due;
            $changed[] = 'due date';
        }

        $placement = $this->placement($context, $project, $arguments, $task);

        if (is_string($placement)) {
            return Response::error($placement);
        }

        $attributes = [...$attributes, ...$placement['save']];
        $changed = [...$changed, ...$placement['changed']];

        if ($changed === []) {
            return Response::error('Nothing to change was given.');
        }

        if ($attributes !== []) {
            $task = $saveTask->update($task, $context->user, $attributes);
        }

        if ($this->moves($task, $placement['move'])) {
            $task = $moveTask->handle($task, $placement['move']);
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
            'sprint' => $schema->string()->description('"active", an open sprint\'s name, or "backlog".'),
            'release' => $schema->string()->description('A release name such as v3.2.1, or "none".'),
            'parent' => $schema->string()->description('Make this a subtask of the task with this reference (ENG-12), or "none" to remove its parent.'),
            'add_labels' => $schema->array()->items($schema->string())->description('Label names to add.'),
            'remove_labels' => $schema->array()->items($schema->string())->description('Label names to remove.'),
        ];
    }
}
