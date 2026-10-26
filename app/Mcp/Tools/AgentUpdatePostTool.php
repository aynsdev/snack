<?php

namespace App\Mcp\Tools;

use App\Actions\Messages\UpdateMessage;
use App\Mcp\Tools\Concerns\ServesAgents;
use App\Models\Message;
use App\Services\ActorContext;
use App\Services\Outbound\OutboundEvents;
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
 * Rewrites a status post the Kusina daemon made, in place, rather than
 * posting a new one for every step of a run.
 *
 * Only a post this same connector made can be edited, and its dedupe marker
 * must survive the edit so agent_find_post keeps finding it. Everything else
 * reads as "not found", so the tool never confirms a message exists.
 */
#[Name('agent_update_post')]
#[Title('Agent Update Post')]
#[Description(<<<'TEXT'
For agent connectors only: replaces the body of a post this connector made.
The new body must end with the same ‹la:…› marker the post ends with. Returns
{"message_id": <id>, "edited_at": <timestamp or null>}.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class AgentUpdatePostTool extends SnackTool
{
    use ServesAgents;

    private const MARKER_SUFFIX = '/‹la:[a-z0-9]{8}›$/u';

    public function handle(Request $request, UpdateMessage $updateMessage): Response
    {
        $context = $this->agentContext($request, 'agent_update_post');

        if ($context instanceof Response) {
            return $context;
        }

        $request->validate([
            'message_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:'.config('snack.messages.max_length')],
        ], [
            'body.required' => 'A message cannot be empty.',
            'body.max' => 'That message is too long to send.',
        ]);

        $connectorId = app(ActorContext::class)->current()->connectorId;

        $message = $connectorId === null ? null : Message::query()
            ->where('workspace_id', $context->workspace->id)
            ->where('sender_id', $context->user->id)
            ->whereRaw("metadata->'agent'->>'connector_id' = ?", [$connectorId])
            ->find((int) $request->get('message_id'));

        $container = $message?->container();

        if ($message === null || $container === null || Gate::forUser($context->user)->denies('view', $container)) {
            return Response::error('There is no post by this agent with that id.');
        }

        $marker = $this->marker($message->body);
        $body = trim((string) $request->get('body'));

        if ($marker === null || $this->marker($body) !== $marker) {
            return Response::error('The new body must end with the same marker the post ends with.');
        }

        $updated = $updateMessage->handle($message, $body);

        return $this->json([
            'message_id' => $updated->id,
            'edited_at' => OutboundEvents::timestamp($updated->edited_at),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->integer()->description('The post to rewrite.')->required(),
            'body' => $schema->string()->description('The full new body, ending with the post\'s ‹la:…› marker.')->required(),
        ];
    }

    private function marker(string $body): ?string
    {
        return preg_match(self::MARKER_SUFFIX, $body, $match) === 1 ? $match[0] : null;
    }
}
