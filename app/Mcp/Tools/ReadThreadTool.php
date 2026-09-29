<?php

namespace App\Mcp\Tools;

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
 * A thread: its root and every reply, in order.
 *
 * Named by any message in it — the root or a reply — because a search result
 * may be either, and threads are flat, so both lead to the same conversation.
 *
 * Gated on MessagePolicy::view against the root, which inherits from the
 * container. A message in a DM the person is not part of, or a private channel
 * they were never invited to, reads as "no such message" — the same answer as
 * an id that does not exist, and for the same reason.
 */
#[Name('read_thread')]
#[Title('Read Thread')]
#[Description(<<<'TEXT'
Read a whole thread — the original message and all of its replies, oldest
first. Pass the id of any message in the thread, such as one returned by
`search_messages` or `read_channel`.
TEXT)]
#[IsReadOnly]
class ReadThreadTool extends SnackTool
{
    /**
     * Replies returned at most. A thread longer than this is a meeting, and the
     * tail is the part most worth reading, so the newest are the ones kept.
     */
    private const MAX_REPLIES = 100;

    public function handle(Request $request): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $id = $request->get('message_id');
        $message = is_numeric($id)
            ? Message::query()
                ->where('workspace_id', $context->workspace->id)
                ->with(['channel.workspace', 'conversation.workspace'])
                ->find((int) $id)
            : null;

        $root = $message === null ? null : Message::query()
            ->with(['channel.workspace', 'conversation.workspace', 'sender:id,name,username', 'attachments:id,message_id,original_name'])
            ->find($message->threadRootId());

        if ($root === null || Gate::forUser($context->user)->denies('view', $root)) {
            return Response::error('There is no message with that id that this person can see.');
        }

        $replies = Message::query()
            ->where('parent_message_id', $root->id)
            ->with(['sender:id,name,username', 'attachments:id,message_id,original_name'])
            ->orderByDesc('id')
            ->limit(self::MAX_REPLIES)
            ->get()
            ->reverse();

        $where = $root->channel !== null ? '#'.$root->channel->name : 'a direct message';

        $lines = [
            "Thread in {$where}",
            '',
            $this->formatMessage($root),
            ...$replies->map(fn (Message $reply): string => $this->formatMessage($reply))->all(),
        ];

        if ($replies->isEmpty()) {
            $lines[] = '(no replies)';
        }

        return Response::text(implode("\n\n", array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->integer()
                ->description('The id of any message in the thread.')
                ->required(),
        ];
    }
}
