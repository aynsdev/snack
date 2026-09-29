<?php

namespace App\Mcp\Tools;

use App\Actions\Messages\SendMessage;
use App\Models\Message;
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
 * Post a message into a channel, as the person who connected the client.
 *
 * As them, not as a bot: the token is theirs and so is the message. That is the
 * honest attribution — the person asked their assistant to say it — and it
 * means everything that applies to a message they typed applies here too:
 * ChannelPolicy::post (joined, not archived, announcement rights), mentions,
 * notifications, threads, and Bite being summoned by name.
 *
 * Runs through {@see SendMessage}, the same action the composer uses, so there
 * is no second path for a message to arrive by.
 */
#[Name('send_message')]
#[Title('Send Message')]
#[Description(<<<'TEXT'
Post a message to a channel as the person who connected this assistant. It
appears under their name, notifies anyone @mentioned, and cannot be sent
anonymously — only send what the person has asked you to send.

Name the channel as `#name` or `name`; they must already be a member of it.
To reply in a thread, pass the id of any message in that thread as `reply_to`.
Mention people with `@username`.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class SendMessageTool extends SnackTool
{
    public function handle(Request $request, SendMessage $sendMessage): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->validate([
            'channel' => ['required', 'string'],
            'body' => ['required', 'string', 'max:'.config('snack.messages.max_length')],
            'reply_to' => ['nullable', 'integer'],
        ], [
            'body.required' => 'A message cannot be empty.',
            'body.max' => 'That message is too long to send.',
        ]);

        $channel = $this->channel($context, $request->get('channel'));

        if ($channel === null) {
            return Response::error('There is no channel by that name that this person can see. Use `browse_workspace` with list=channels to find the right name.');
        }

        $allowed = Gate::forUser($context->user)->inspect('post', $channel);

        if ($allowed->denied()) {
            return Response::error("This person cannot post in #{$channel->name}: {$allowed->message()}");
        }

        /*
         * Resolved through the channel, so a reply cannot attach itself to a
         * message somewhere else — the same guarantee StoreMessageRequest gives
         * the composer.
         */
        $parent = null;

        if ($request->get('reply_to') !== null) {
            $parent = Message::query()
                ->where('channel_id', $channel->id)
                ->find((int) $request->get('reply_to'));

            if ($parent === null) {
                return Response::error("There is no message #{$request->get('reply_to')} in #{$channel->name} to reply to.");
            }
        }

        $message = $sendMessage->handle(
            container: $channel,
            sender: $context->user,
            body: (string) $request->get('body'),
            parent: $parent,
        );

        return Response::text($parent !== null
            ? "Replied in the thread on message #{$parent->threadRootId()} in #{$channel->name} (message #{$message->id})."
            : "Posted message #{$message->id} in #{$channel->name}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('The channel to post in, as #name or name.')
                ->required(),
            'body' => $schema->string()
                ->description('The message text. Markdown and @username mentions are supported.')
                ->required(),
            'reply_to' => $schema->integer()
                ->description('Optional: the id of a message whose thread this should be posted in.'),
        ];
    }
}
