<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\MoveTask;
use App\Actions\Projects\SaveTask;
use App\Mcp\Tools\Concerns\PlacesTasks;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Move several cards at once — "everything in v3.2.1 to Done" — as one
 * permission prompt instead of one per card.
 *
 * All or nothing at the level that matters to the person: every task is
 * resolved and checked before any is written, so a typo in the tenth
 * reference does not leave nine moved and one not. Each write still goes
 * through {@see SaveTask} and {@see MoveTask} one card at a time, so the
 * board's rules and its live updates are exactly those of a drag.
 */
#[Name('update_tasks')]
#[Title('Update Tasks')]
#[Description(<<<'TEXT'
Apply the same change to several tasks at once: move them to a status column,
a sprint, or a release. Pass up to 50 task references, such as
["ENG-42", "ENG-43"], and at least one of:

- `status`: a column name, such as "Done".
- `sprint`: "active", an open sprint's name, or "backlog". Unfinished
  subtasks move with their parent.
- `release`: a release name such as "v3.2.1", or "none".

Every task is checked first; if any reference or name does not resolve, or
the person cannot change one of them, nothing is changed and the reply says
why. Only change what the person asked you to change.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class UpdateTasksTool extends SnackTool
{
    use PlacesTasks;
    use ResolvesProjectWork;

    /**
     * The most cards one call may touch: a release's worth, not a board's.
     */
    private const MAX_TASKS = 50;

    public function handle(Request $request, SaveTask $saveTask, MoveTask $moveTask): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->validate([
            'tasks' => ['required', 'array', 'min:1', 'max:'.self::MAX_TASKS],
            'tasks.*' => ['string'],
            'status' => ['sometimes', 'string'],
            'sprint' => ['sometimes', 'string'],
            'release' => ['sometimes', 'string'],
        ]);

        $change = array_intersect_key($request->all(), array_flip(['status', 'sprint', 'release']));

        if ($change === []) {
            return Response::error('Nothing to change was given. Pass status, sprint, or release.');
        }

        $plans = [];
        $problems = [];

        foreach (array_unique(array_map('strtoupper', $request->get('tasks'))) as $reference) {
            $task = $this->task($context, $reference);

            if ($task === null) {
                $problems[] = "{$reference}: no task with that reference that this person can see.";

                continue;
            }

            $allowed = Gate::forUser($context->user)->inspect('update', $task);

            if ($allowed->denied()) {
                $problems[] = "{$reference}: {$allowed->message()}";

                continue;
            }

            $placement = $this->placement($context, $task->project, $change, $task);

            if (is_string($placement)) {
                $problems[] = "{$reference}: ".str_replace(' Nothing was changed.', '', $placement);

                continue;
            }

            $plans[] = [$task, $placement];
        }

        if ($problems !== []) {
            return Response::error("Nothing was changed.\n".implode("\n", $problems));
        }

        $references = [];

        /** @var Task $task */
        foreach ($plans as [$task, $placement]) {
            if ($placement['save'] !== []) {
                $task = $saveTask->update($task, $context->user, $placement['save']);
            }

            if ($this->moves($task, $placement['move'])) {
                $moveTask->handle($task, $placement['move']);
            }

            $references[] = $task->reference();
        }

        return Response::text('Updated '.implode(', ', $references).': '.implode(', ', $plans[0][1]['changed']).'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tasks' => $schema->array()->items($schema->string())->description('Task references, such as ["ENG-42", "ENG-43"].')->required(),
            'status' => $schema->string()->description('Move them all to this column, by name.'),
            'sprint' => $schema->string()->description('"active", an open sprint\'s name, or "backlog".'),
            'release' => $schema->string()->description('A release name such as v3.2.1, or "none".'),
        ];
    }
}
