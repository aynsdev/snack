<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\SaveTask;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Mcp\Tools\Concerns\PlacesTasks;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\Task;
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
 * File a task on a project board as the person who connected the client.
 *
 * Bite's {@see App\Ai\Tools\CreateTask} files a title on a board and pauses for
 * an in-app approval card. An MCP client has no card — its own tool-permission
 * prompt is the confirmation — and wants to place the card fully in one call
 * (column, sprint, release, parent, labels), so this resolves everything first
 * and refuses with the valid options when a name matches nothing. One
 * {@see SaveTask::create()} then writes it, so the board's rules and the
 * assignee's notification apply exactly as they do on screen.
 *
 * Gated on TaskPolicy::create — the project's contributors only.
 */
#[Name('create_task')]
#[Title('Create Task')]
#[Description(<<<'TEXT'
Create a task (an issue) on a project board, under the name of the person who
connected this assistant. Only create one when the person asked you to.

Before calling this, search with `search_tasks` to check it has not already
been filed. Name the project by key (ENG) or name; if it matches nothing, the
reply lists the projects that exist so you can ask which one was meant.

Optional placement, all resolved within the project:
- `status`: a column name from `get_project`. Defaults to the first column.
- `sprint`: "active", an open sprint's name, or "backlog" (the default).
- `release`: a release name such as "v3.2.1". See `list_releases`.
- `parent`: the reference of any task in the same project, such as ENG-12,
  to make this its subtask. An epic cannot have a parent.
- `labels`: label names the project already has.
- `assignee`: @username of a project member, or "me". They are notified.

A name that matches nothing is refused with the valid options, and nothing is
created. Write the title as a specific, actionable sentence and put the
context in the description.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class CreateTaskTool extends SnackTool
{
    use PlacesTasks;
    use ResolvesProjectWork;

    public function handle(Request $request, SaveTask $saveTask): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->validate([
            'project' => ['required', 'string'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'type' => ['sometimes', Rule::enum(TaskType::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'assignee' => ['sometimes', 'string'],
            'status' => ['sometimes', 'string'],
            'sprint' => ['sometimes', 'string'],
            'release' => ['sometimes', 'string'],
            'parent' => ['sometimes', 'string'],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['string'],
            'due_on' => ['sometimes', 'string'],
            'story_points' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $project = $this->project($context, $request->get('project'));

        if ($project === null || $project->is_archived) {
            return Response::error($this->noSuchProject($context, $request->get('project')));
        }

        $allowed = Gate::forUser($context->user)->inspect('create', [Task::class, $project]);

        if ($allowed->denied()) {
            return Response::error("This person cannot create tasks on {$project->key}: {$allowed->message()}");
        }

        $arguments = $request->all();

        $attributes = [
            'title' => trim((string) $arguments['title']),
            'description' => $arguments['description'] ?? null,
            'type' => $arguments['type'] ?? TaskType::Task->value,
            'priority' => $arguments['priority'] ?? TaskPriority::Medium->value,
            'story_points' => $arguments['story_points'] ?? null,
        ];

        if (array_key_exists('assignee', $arguments)) {
            $person = $this->projectMember($project, $arguments['assignee'], $context->user);

            if ($person === null) {
                return Response::error("Nobody on {$project->key} matches [{$arguments['assignee']}]. They must be a member of the project to be assigned. Nothing was created.");
            }

            $attributes['assignee_id'] = $person->id;
        }

        if (array_key_exists('due_on', $arguments)) {
            $due = $this->dueDate($arguments['due_on']);

            if ($due === false) {
                return Response::error("[{$arguments['due_on']}] is not a date. Use a date like 2026-10-31. Nothing was created.");
            }

            $attributes['due_on'] = $due;
        }

        $placement = $this->placement($context, $project, ['type' => $attributes['type'], ...$arguments]);

        if (is_string($placement)) {
            return Response::error(str_replace('Nothing was changed.', 'Nothing was created.', $placement));
        }

        $task = $saveTask->create($project, $context->user, [
            ...$attributes,
            ...$placement['save'],
            ...array_intersect_key($placement['move'], array_flip(['board_column_id', 'sprint_id'])),
        ]);

        $task->load(['column:id,name', 'sprint:id,name', 'release:id,name', 'assignee:id,name,username']);

        $details = array_filter([
            $task->column->name,
            $project->uses_sprints ? ($task->sprint->name ?? 'backlog') : null,
            $task->release !== null ? "release {$task->release->name}" : null,
            $task->assignee !== null ? "assigned to @{$task->assignee->username}" : null,
        ]);

        return Response::text("Created {$task->reference()} — {$task->title} on {$project->name} (".implode(', ', $details).').');
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project key (ENG) or name.')->required(),
            'title' => $schema->string()->description('A specific, actionable one-line summary.')->required(),
            'description' => $schema->string()->description('The detail: what was said, what is expected, and any links.'),
            'type' => $schema->string()->enum(TaskType::values()),
            'priority' => $schema->string()->description('Defaults to medium.')->enum(TaskPriority::values()),
            'assignee' => $schema->string()->description('@username of a project member, or "me".'),
            'status' => $schema->string()->description('A column name. Defaults to the first column.'),
            'sprint' => $schema->string()->description('"active", an open sprint\'s name, or "backlog".'),
            'release' => $schema->string()->description('A release name such as v3.2.1.'),
            'parent' => $schema->string()->description('The reference of a task in the same project to file this under.'),
            'labels' => $schema->array()->items($schema->string())->description('Label names the project already has.'),
            'due_on' => $schema->string()->description('A date such as 2026-10-31.'),
            'story_points' => $schema->integer()->min(0)->max(999),
        ];
    }
}
