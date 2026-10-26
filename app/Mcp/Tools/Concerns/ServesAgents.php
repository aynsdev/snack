<?php

namespace App\Mcp\Tools\Concerns;

use App\Ai\BiteContext;
use App\Enums\ActorVia;
use App\Models\Message;
use App\Models\User;
use App\Services\ActorContext;
use App\Services\Outbound\OutboundEvents;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * The structured tools the Kusina daemon reads Snack through when it
 * makes a decision — who approved, which thread is a task's, whether agents
 * are on — rather than parsing the prose the assistant tools print, which a
 * name, a title or a file name can be made to imitate.
 *
 * For agent connectors only, the same as `agent_attestation`: the daemon
 * calls them itself and never offers them to the model. Every answer is one
 * JSON object, so a value is always a string inside it and never a line of
 * its own.
 */
trait ServesAgents
{
    /**
     * Listed, and callable, only for an agent connector: an assistant has no
     * use for these, and should not be offered them. The check in
     * {@see agentContext()} stays as a second line.
     */
    public function shouldRegister(ActorContext $actors): bool
    {
        return $actors->current()->via === ActorVia::Agent;
    }

    /**
     * The caller, when it is its owner's agent connector; the error to answer
     * with otherwise.
     */
    protected function agentContext(Request $request, string $tool): BiteContext|Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        if (! app(ActorContext::class)->current()->isAgentFor($context->user)) {
            return Response::error("{$tool} requires an agent connector. Create one on the workspace's AI connectors page with the kind set to Agent.");
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): Response
    {
        return Response::text(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * One message, key by key, as the viewer may see it. Expects `sender`
     * loaded.
     *
     * @return array<string, mixed>
     */
    protected function agentMessage(Message $message, User $viewer): array
    {
        $agent = $message->metadata['agent'] ?? null;
        $connectorId = is_array($agent) ? ($agent['connector_id'] ?? null) : null;

        return [
            'id' => $message->id,
            'parent_message_id' => $message->parent_message_id,
            'type' => $message->type->value,
            'sender' => $message->sender === null ? null : [
                'id' => $message->sender->id,
                'username' => $message->sender->username,
            ],
            'via_agent' => is_array($agent),
            // Only for the caller's own posts: which token somebody else's
            // agent uses is theirs to know.
            'agent_connector_id' => is_string($connectorId) && $message->sender_id === $viewer->id ? $connectorId : null,
            'edited' => $message->edited_at !== null,
            'created_at' => OutboundEvents::timestamp($message->created_at),
            'body' => $message->body,
            'body_plaintext' => $message->body_plaintext,
        ];
    }
}
