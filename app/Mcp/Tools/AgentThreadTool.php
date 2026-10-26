<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ServesAgents;
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
 * A channel thread as data: its root and its replies after a cursor, oldest
 * first. What the Kusina daemon reads approvals and feedback from.
 *
 * Gated like `read_thread` — MessagePolicy::view on the root — and channels
 * only: an agent's threads live in its project's agent channel, never in a DM.
 * Deleted messages are left out, the root included.
 */
#[Name('agent_thread')]
#[Title('Agent Thread')]
#[Description(<<<'TEXT'
For agent connectors only: a channel thread as one JSON object — its root and
its replies in id order after `after_id`, at most `limit` (default 100, up to
200) at a time. `next_after_id` is the cursor for the next page, or null at the
end.
TEXT)]
#[IsReadOnly]
class AgentThreadTool extends SnackTool
{
    use ServesAgents;

    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 200;

    public function handle(Request $request): Response
    {
        $context = $this->agentContext($request, 'agent_thread');

        if ($context instanceof Response) {
            return $context;
        }

        $id = $request->get('message_id');
        $afterId = $request->get('after_id', 0);
        $limit = $request->get('limit', self::DEFAULT_LIMIT);

        if (! is_int($afterId) || $afterId < 0 || ! is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            return Response::error('after_id must be a whole number of 0 or more, and limit a whole number from 1 to '.self::MAX_LIMIT.'.');
        }

        $message = is_int($id)
            ? Message::query()->where('workspace_id', $context->workspace->id)->find($id)
            : null;

        $root = $message === null ? null : Message::query()
            ->where('workspace_id', $context->workspace->id)
            ->whereNotNull('channel_id')
            ->with(['channel.workspace', 'sender:id,username'])
            ->find($message->threadRootId());

        if ($root === null || Gate::forUser($context->user)->denies('view', $root)) {
            return Response::error('There is no message with that id that this person can see.');
        }

        $replies = Message::query()
            ->where('parent_message_id', $root->id)
            ->where('id', '>', $afterId)
            ->with('sender:id,username')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $page = $replies->take($limit);

        return $this->json([
            'thread_root_id' => $root->id,
            'channel' => ['id' => $root->channel->id, 'name' => $root->channel->name],
            'root' => $this->agentMessage($root, $context->user),
            'messages' => $page->map(fn (Message $reply): array => $this->agentMessage($reply, $context->user))->values()->all(),
            'next_after_id' => $replies->count() > $limit ? $page->last()?->id : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->integer()->description('The id of any message in the thread.')->required(),
            'after_id' => $schema->integer()->description('Only replies with a higher id. 0, or left out, for the first page.'),
            'limit' => $schema->integer()->description('Replies per page, 1 to 200. Default 100.'),
        ];
    }
}
