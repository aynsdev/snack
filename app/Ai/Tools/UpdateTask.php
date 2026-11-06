<?php

namespace App\Ai\Tools;

use App\Actions\Projects\MoveTask;
use App\Actions\Projects\SaveTask;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Mcp\Tools\Concerns\PlacesTasks;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\BoardColumn;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Changes a task that is already on a board: who has it, where it sits, how
 * urgent it is, what it is called.
 *
 * The follow-up to {@see CreateTask}. "Assign this to Renz" under the task Bite
 * just filed is the obvious next thing to say, and without this Bite could only
 * reply that it cannot.
 *
 * # It asks first, like CreateTask
 *
 * Reassigning notifies somebody and moving a card to Done closes it, so the
 * person who summoned Bite gets a card spelling out the change, resolved to real
 * names, and a button. The policy is checked in {@see self::handle()}, after
 * the click and against the asker: the card is a confirmation, not a grant.
 *
 * # Same words as the MCP update_task
 *
 * Task references, member names and column names are resolved by the same
 * concerns {@see App\Mcp\Tools\UpdateTaskTool} uses, so "Done", "@renz" and
 * "PCA-578" mean the same thing to Bite as they do to a connected assistant.
 * Only the fields the model sent are touched, and a name that matches nothing
 * changes nothing and returns the valid options.
 */
class UpdateTask extends BiteTool implements Approvable, Tool
{
    use InteractsWithApprovals;
    use PlacesTasks;
    use ResolvesProjectWork;

    /**
     * The fields copied straight through to {@see SaveTask::update()}.
     */
    private const DIRECT_FIELDS = ['title', 'description', 'priority', 'type'];

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Change an existing task: assign or unassign it, move it to another
        status column, or change its priority, type, title, description, or due
        date. Only the fields you pass are changed.

        Pass the task reference, such as ENG-42. When the person says "this" or
        "it", use the reference from the conversation; if there is none, find it
        with `search_tasks` first. Never guess a reference.

        - `assignee`: @username or name of a project member, or "none" to
          unassign. The new assignee is notified.
        - `status`: a column name on that project's board, such as "Done".
        - `due_on`: a date (2026-10-31), or "none" to clear it.

        If a name matches nothing, nothing is changed and this tool answers with
        the valid options: ask the person which one they meant.

        Only change what the person asked you to change. They will be shown the
        change and must approve it, so do not ask for confirmation yourself.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = $this->arguments($request);
        $task = $this->task($this->context, $arguments['task'] ?? null);

        if ($task === null) {
            return 'There is no task with that reference that this person can see. References look like ENG-42. Nothing was changed.';
        }

        /*
         * After the approval, not before it. The click says the person wants
         * this; the policy says whether they may, and a contributor's rights
         * can have been taken away between the card and the click.
         */
        $allowed = Gate::forUser($this->context->user)->inspect('update', $task);

        if ($allowed->denied()) {
            return "This person cannot change {$task->reference()}: {$allowed->message()}";
        }

        $plan = $this->plan($task, $arguments);

        if (is_string($plan)) {
            return $plan;
        }

        if ($plan['attributes'] !== []) {
            $task = app(SaveTask::class)->update($task, $this->context->user, $plan['attributes']);
        }

        if ($this->moves($task, $plan['move'])) {
            $task = app(MoveTask::class)->handle($task, $plan['move']);
        }

        return sprintf(
            'Updated %s: %s. Tell the person what changed, using that reference.',
            $task->reference(),
            implode(', ', $plan['changed']),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->description('The task reference, such as ENG-42.')
                ->required(),
            'assignee' => $schema->string()
                ->description('@username or name of a project member, or "none" to unassign.'),
            'status' => $schema->string()
                ->description('Move it to this board column, by name, such as "Done".'),
            'priority' => $schema->string()
                ->enum(TaskPriority::values()),
            'type' => $schema->string()
                ->enum(TaskType::values()),
            'title' => $schema->string()
                ->description('A new one-line title.'),
            'description' => $schema->string()
                ->description('A new description. Replaces the old one entirely.'),
            'due_on' => $schema->string()
                ->description('A date such as 2026-10-31, or "none".'),
        ];
    }

    /**
     * The change, spelled out with the names it resolved to.
     *
     * Resolved rather than echoed, so the person can see the model picked the
     * right task and the right Renz before anybody is notified. When something
     * does not resolve the card still goes up, naming what the model asked for;
     * the tool then refuses it and says why.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $arguments = $this->arguments($request);
        $named = is_string($arguments['task'] ?? null) ? strtoupper(trim($arguments['task'])) : 'a task';
        $task = $this->task($this->context, $arguments['task'] ?? null);

        $plan = $task === null ? null : $this->plan($task, $arguments);

        $changes = is_array($plan)
            ? $plan['changed']
            : array_map(
                fn (string $field): string => "{$field} → ".$this->term($arguments[$field], 60),
                array_keys(array_diff_key($arguments, ['task' => true])),
            );

        $where = $task === null ? $named : "{$task->reference()} “{$this->term($task->title, 80)}”";

        return Approval::required(sprintf('Change %s: %s.', $where, implode(', ', $changes) ?: 'nothing'));
    }

    /**
     * Everything this call would change, or the reason it cannot.
     *
     * Shared by the approval card and the handler so the two cannot disagree
     * about what "assign to renz" means.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{attributes: array<string, mixed>, move: array<string, mixed>, changed: list<string>}|string
     */
    private function plan(Task $task, array $arguments): array|string
    {
        $project = $task->project;
        $attributes = [];
        $changed = [];

        foreach (self::DIRECT_FIELDS as $field) {
            if (! array_key_exists($field, $arguments)) {
                continue;
            }

            $value = $this->field($field, $arguments[$field]);

            if ($value === null) {
                return "[{$this->term($arguments[$field], 60)}] is not a valid {$field}. Nothing was changed.";
            }

            $attributes[$field] = $value;
            $changed[] = match ($field) {
                'title' => "title → “{$this->term($value, 80)}”",
                'description' => 'description',
                default => "{$field} → {$value}",
            };
        }

        if (array_key_exists('assignee', $arguments)) {
            $named = $this->term($arguments['assignee'], 60);

            if (in_array(strtolower($named), ['none', 'nobody', 'unassigned'], true)) {
                $attributes['assignee_id'] = null;
                $changed[] = 'unassigned';
            } else {
                $person = $this->projectMember($project, $named, $this->context->user);

                if ($person === null) {
                    return "Nobody on {$project->key} matches [{$named}]. They must be a member of the project to be assigned, so say so. Nothing was changed.";
                }

                $attributes['assignee_id'] = $person->id;
                $changed[] = "assignee → {$person->name}";
            }
        }

        if (array_key_exists('due_on', $arguments)) {
            $due = $this->dueDate($arguments['due_on']);

            if ($due === false) {
                return "[{$this->term($arguments['due_on'], 40)}] is not a date. Use a date like 2026-10-31, or \"none\". Nothing was changed.";
            }

            $attributes['due_on'] = $due;
            $changed[] = 'due date → '.($due ?? 'none');
        }

        $move = [];

        if (array_key_exists('status', $arguments)) {
            $column = $this->column($project, $arguments['status']);

            if (! $column instanceof BoardColumn) {
                return $column;
            }

            $move['board_column_id'] = $column->id;
            $changed[] = "status → {$column->name}";
        }

        if ($changed === []) {
            return 'Nothing to change was given. Say what should change.';
        }

        return ['attributes' => $attributes, 'move' => $move, 'changed' => $changed];
    }

    /**
     * One of the fields saved as given, cleaned, or null when it is not valid.
     *
     * @param  'title'|'description'|'priority'|'type'  $field
     */
    private function field(string $field, mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return match ($field) {
            'title' => $text === '' ? null : mb_substr(str($text)->squish()->toString(), 0, 200),
            'description' => mb_substr($text, 0, 5000),
            'priority' => TaskPriority::tryFrom(strtolower($text))?->value,
            'type' => TaskType::tryFrom(strtolower($text))?->value,
        };
    }

    /**
     * The tool call's arguments, minus the ones the model sent as null.
     *
     * A model fills optional fields with null as often as it omits them, and a
     * null here must mean "not mentioned", never "clear it": that is what the
     * explicit "none" is for.
     *
     * @return array<string, mixed>
     */
    private function arguments(Request $request): array
    {
        return array_filter(
            array_intersect_key($request->toArray(), array_flip(['task', 'assignee', 'status', 'due_on', ...self::DIRECT_FIELDS])),
            fn (mixed $value): bool => $value !== null,
        );
    }
}
