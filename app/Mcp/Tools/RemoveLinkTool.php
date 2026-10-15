<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\LinkTask;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\TaskLink;
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
 * Take a web link off a card, gated on TaskPolicy::update.
 */
#[Name('remove_link')]
#[Title('Remove Link')]
#[Description(<<<'TEXT'
Remove a web link from a task. Pass the task reference and the link's URL as
`get_task` lists it. Only remove links when the person asked you to.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive]
class RemoveLinkTool extends SnackTool
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
            'url' => ['required', 'string'],
        ]);

        $task = $this->task($context, $request->get('task'));

        if ($task === null) {
            return Response::error('There is no task with that reference that this person can see. References look like ENG-42.');
        }

        $allowed = Gate::forUser($context->user)->inspect('update', $task);

        if ($allowed->denied()) {
            return Response::error("This person cannot change {$task->reference()}: {$allowed->message()}");
        }

        $link = $task->links()->where('url', trim((string) $request->get('url')))->first();

        if (! $link instanceof TaskLink) {
            $urls = $task->links()->pluck('url')->implode(', ');

            return Response::error("{$task->reference()} has no link to that URL. "
                .($urls === '' ? 'It has no links.' : "Its links are: {$urls}."));
        }

        $linkTask->removeLink($link);

        return Response::text("Removed the link to {$link->url} from {$task->reference()}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task reference, such as ENG-42.')->required(),
            'url' => $schema->string()->description('The URL of the link to remove.')->required(),
        ];
    }
}
