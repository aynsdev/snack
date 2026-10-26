<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ScopesAgentReads;
use App\Models\Message;
use App\Support\OneLine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * "What has been going on in #design?"
 *
 * The recent timeline of one channel, oldest first, the way the channel reads
 * on screen. Replies are left out, as they are from the timeline — a message
 * with replies says how many, and `read_thread` opens them.
 *
 * Gated on ChannelPolicy::view, which is the rule that decides whether the
 * channel page renders at all. A member previewing a public channel they have
 * not joined can read it here too, exactly as they could by clicking on it.
 */
#[Name('read_channel')]
#[Title('Read Channel')]
#[Description(<<<'TEXT'
Read the most recent messages in one channel, oldest first. Use this to catch
up on a channel or to see the context around something `search_messages`
found.

Name the channel as `#name` or `name`. Thread replies are not included; a
message that has replies says how many, and `read_thread` opens them. To page
further back, pass the lowest message id you have seen as `before`.
TEXT)]
#[IsReadOnly]
class ReadChannelTool extends SnackTool
{
    use ScopesAgentReads;

    public function handle(Request $request): Response
    {
        $context = $this->readContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $named = $request->get('channel');
        $channel = $this->channel($context, $named);

        if ($channel === null) {
            return Response::error(sprintf(
                'There is no channel called [%s] that this person can see. Use `browse_workspace` with list=channels to find the right name.',
                is_string($named) ? $named : '',
            ));
        }

        $limit = max(1, min((int) ($request->get('limit') ?: 20), 50));
        $before = is_numeric($request->get('before')) ? (int) $request->get('before') : null;

        $messages = $channel->messages()
            ->rootLevel()
            ->before($before)
            ->with(['sender:id,name,username', 'attachments:id,message_id,original_name'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            // Queried newest-first to walk the index, then flipped: a
            // transcript reads forwards.
            ->reverse()
            ->map(fn (Message $message): string => $this->formatMessage($message))
            ->values()
            ->all();

        $name = OneLine::clean($channel->name);

        if ($messages === []) {
            return Response::text("#{$name} has no messages".($before !== null ? " before message #{$before}." : ' yet.'));
        }

        $topic = $channel->topic !== null && $channel->topic !== '' ? ' — '.OneLine::clean($channel->topic) : '';

        return Response::text("#{$name}{$topic}\n\n".implode("\n\n", $messages));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('The channel to read, as #name or name.')
                ->required(),
            'limit' => $schema->integer()
                ->description('How many messages to return. Defaults to 20, maximum 50.')
                ->min(1)
                ->max(50),
            'before' => $schema->integer()
                ->description('Optional: only messages older than this message id, to page backwards.'),
        ];
    }
}
