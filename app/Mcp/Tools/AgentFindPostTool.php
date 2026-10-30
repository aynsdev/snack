<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ServesAgents;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Finds a post the Kusina daemon made, by the marker it ended the post
 * with — how it recovers a message id it never heard back after a timeout,
 * rather than posting twice.
 *
 * Only the caller's own agent posts count, so no marker anybody else typed can
 * be passed off as the daemon's post.
 */
#[Name('agent_find_post')]
#[Title('Agent Find Post')]
#[Description(<<<'TEXT'
For agent connectors only: the id of the newest message in the channel that
this person's agent posted in the last 7 days and that ends with the marker,
as {"message_id": <id or null>}.
TEXT)]
#[IsReadOnly]
class AgentFindPostTool extends SnackTool
{
    use ServesAgents;

    public const MARKER_PATTERN = '/^‹la:[a-z0-9]{8}›$/u';

    /**
     * How far back to look: a daemon recovering from a timeout is looking for
     * something it posted moments ago.
     */
    private const WITHIN_DAYS = 7;

    public function handle(Request $request): Response
    {
        $context = $this->agentContext($request, 'agent_find_post');

        if ($context instanceof Response) {
            return $context;
        }

        $marker = $request->get('marker');

        if (! is_string($marker) || preg_match(self::MARKER_PATTERN, $marker) !== 1) {
            return Response::error('The marker must be ‹la: followed by 8 lowercase letters or digits and ›.');
        }

        $channelId = $request->get('channel_id');
        $channel = is_int($channelId)
            ? Channel::query()->where('workspace_id', $context->workspace->id)->with('workspace')->find($channelId)
            : null;

        if ($channel === null || Gate::forUser($context->user)->denies('view', $channel)) {
            return Response::error('There is no channel with that id that this person can see.');
        }

        /*
         * The suffix match is safe as a LIKE pattern because the marker's
         * format admits neither `%` nor `_`. An `agent` key holding JSON null
         * is not an agent's post.
         */
        $found = Message::query()
            ->where('workspace_id', $context->workspace->id)
            ->where('sender_id', $context->user->id)
            ->where('channel_id', $channel->id)
            ->where('created_at', '>=', now()->subDays(self::WITHIN_DAYS))
            ->whereRaw("jsonb_typeof(metadata->'agent') = 'object'")
            ->where('body', 'like', '%'.$marker)
            ->orderByDesc('id')
            ->value('id');

        return $this->json(['message_id' => $found]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel_id' => $schema->integer()->description('The channel the post was made in.')->required(),
            'marker' => $schema->string()->description('The marker the post ends with: ‹la: then 8 lowercase letters or digits, then ›.')->required(),
        ];
    }
}
