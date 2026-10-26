<?php

namespace App\Mcp\Tools\Concerns;

use App\Ai\BiteContext;
use App\Models\Message;
use App\Services\ActorContext;
use App\Services\AgentReadScope;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * A read tool that an agent connector may narrow with `as_seen_by` and
 * `audience_channel_id` — see {@see AgentReadScope}. Both are refused from any
 * other connector, so they can never be used to widen what a call sees.
 */
trait ScopesAgentReads
{
    protected function honorsReadScope(): bool
    {
        return true;
    }

    protected function readContext(Request $request): BiteContext|Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        if (! AgentReadScope::requested($request->all())) {
            return $context;
        }

        if (! app(ActorContext::class)->current()->isAgentFor($context->user)) {
            return Response::error('as_seen_by and audience_channel_id are accepted only from agent connectors.');
        }

        $scope = AgentReadScope::resolve(
            $context->workspace,
            $context->user,
            $request->get(AgentReadScope::VIEWERS),
            $request->get(AgentReadScope::AUDIENCE),
        );

        return is_string($scope)
            ? Response::error($scope)
            : new BiteContext(workspace: $context->workspace, user: $context->user, container: null, readScope: $scope);
    }

    protected function readableMessage(BiteContext $context, Message $message): bool
    {
        return Gate::forUser($context->user)->allows('view', $message)
            && ($context->readScope?->allowsMessage($message) ?? true);
    }
}
