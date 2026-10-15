<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\LinkTask;
use App\Enums\TaskRelationType;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Relate one card to another: blocks, relates to, duplicates.
 *
 * Needs the right to change the first card and only to see the second — the
 * relation is said from the first card's side, as it is on the task panel.
 */
#[Name('link_tasks')]
#[Title('Link Tasks')]
#[Description(<<<'TEXT'
Link one task to another. Read it as "`task` <type> `other`":

- `blocks`: ENG-1 blocks ENG-2 (ENG-2 shows "is blocked by ENG-1").
- `relates_to`: the two are related.
- `duplicates`: ENG-1 duplicates ENG-2.

The tasks may be in different projects of this workspace. Only link tasks
when the person asked you to.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class LinkTasksTool extends SnackTool
{
    use ResolvesProjectWork;

    public function handle(Request $request, LinkTask $linkTask): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->validate([
            'task' => ['required', 'string'],
            'other' => ['required', 'string'],
            'type' => ['required', Rule::enum(TaskRelationType::class)],
        ]);

        $task = $this->task($context, $request->get('task'));
        $other = $this->task($context, $request->get('other'));

        if ($task === null || $other === null) {
            $missing = $task === null ? $request->get('task') : $request->get('other');

            return Response::error("There is no task [{$missing}] that this person can see. References look like ENG-42.");
        }

        $allowed = Gate::forUser($context->user)->inspect('update', $task);

        if ($allowed->denied()) {
            return Response::error("This person cannot change {$task->reference()}: {$allowed->message()}");
        }

        $type = TaskRelationType::from($request->get('type'));

        try {
            $linkTask->relate($task, $other, $type, $context->user);
        } catch (ValidationException $exception) {
            return Response::error($exception->validator->errors()->first());
        }

        return Response::text("Linked: {$task->reference()} {$type->outward()} {$other->reference()}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task the link is said from, such as ENG-1.')->required(),
            'other' => $schema->string()->description('The task it points at, such as ENG-2.')->required(),
            'type' => $schema->string()->enum(TaskRelationType::values())->required(),
        ];
    }
}
