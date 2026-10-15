<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\LinkTask;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
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
 * Remove every link between two cards, whichever side made it.
 */
#[Name('unlink_tasks')]
#[Title('Unlink Tasks')]
#[Description(<<<'TEXT'
Remove the links between two tasks (blocks, relates to, duplicates), in
either direction. Only unlink tasks when the person asked you to.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive]
class UnlinkTasksTool extends SnackTool
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

        $removed = $linkTask->unrelate($task, $other);

        return $removed === 0
            ? Response::error("{$task->reference()} and {$other->reference()} are not linked.")
            : Response::text("Unlinked {$task->reference()} and {$other->reference()}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('One task reference, such as ENG-1.')->required(),
            'other' => $schema->string()->description('The other task reference, such as ENG-2.')->required(),
        ];
    }
}
