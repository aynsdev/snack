<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ScopesAgentReads;
use App\Models\Message;
use App\Support\OneLine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One message, with the messages around it — what a link to a message, or a
 * search hit, is about.
 *
 * Gated like `read_thread`: MessagePolicy::view, so a message the person
 * cannot see reads exactly like one that does not exist.
 */
#[Name('get_message')]
#[Title('Get Message')]
#[Description(<<<'TEXT'
Get one message by its id, with a few messages before and after it for
context: the neighbouring replies when it is a thread reply, otherwise the
neighbouring messages in its channel. Use `read_thread` to read a whole thread.
TEXT)]
#[IsReadOnly]
class GetMessageTool extends SnackTool
{
    use ScopesAgentReads;

    private const DEFAULT_AROUND = 3;

    private const MAX_AROUND = 10;

    public function handle(Request $request): Response
    {
        $context = $this->readContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $id = $request->get('message_id');
        $message = is_numeric($id)
            ? Message::query()
                ->where('workspace_id', $context->workspace->id)
                ->with(['channel.workspace', 'conversation.workspace', 'sender:id,name,username', 'attachments:id,message_id,original_name'])
                ->find((int) $id)
            : null;

        if ($message === null || ! $this->readableMessage($context, $message)) {
            return Response::error('There is no message with that id that this person can see.');
        }

        $around = max(0, min((int) ($request->get('around') ?? self::DEFAULT_AROUND), self::MAX_AROUND));
        $siblings = fn (): Builder => Message::query()
            ->when(
                $message->isThreadReply(),
                fn (Builder $query) => $query->where('parent_message_id', $message->parent_message_id),
                fn (Builder $query) => $query
                    ->whereNull('parent_message_id')
                    ->where($message->channel_id !== null ? 'channel_id' : 'conversation_id', $message->channel_id ?? $message->conversation_id),
            )
            ->with(['sender:id,name,username', 'attachments:id,message_id,original_name']);

        $before = $around === 0 ? collect() : $siblings()->where('id', '<', $message->id)->orderByDesc('id')->limit($around)->get()->reverse();
        $after = $around === 0 ? collect() : $siblings()->where('id', '>', $message->id)->orderBy('id')->limit($around)->get();

        $where = $message->channel !== null ? '#'.OneLine::clean($message->channel->name) : 'a direct message';
        $heading = $message->isThreadReply()
            ? "Reply in {$where}, in the thread on message #{$message->parent_message_id}"
            : "Message in {$where}";

        $format = fn (Message $neighbour): string => $this->formatMessage($neighbour);

        return Response::text(implode("\n\n", [
            $heading,
            ...$before->map($format)->all(),
            '>>> '.$this->formatMessage($message),
            ...$after->map($format)->all(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->integer()
                ->description('The id of the message.')
                ->required(),
            'around' => $schema->integer()
                ->description('How many messages to include before and after it. Defaults to 3, maximum 10.')
                ->min(0)
                ->max(self::MAX_AROUND),
        ];
    }
}
