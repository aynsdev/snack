<?php

namespace App\Mcp\Tools;

use App\Actions\Attachments\StoreAgentImages;
use App\Actions\Messages\SendMessage;
use App\Ai\BiteContext;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use App\Models\Message;
use App\Services\ActorContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use PDOException;

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
    use ResolvesProjectWork;

    private const AGENT_KINDS = ['root', 'plan', 'reply', 'notice'];

    /**
     * Images per person per minute. An agent posts a handful at the end of a
     * run; this stops one stuck in a retry loop from filling the disk.
     */
    private const IMAGES_PER_MINUTE = 30;

    public function handle(Request $request, SendMessage $sendMessage, StoreAgentImages $storeImages): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->validate([
            'channel' => ['required', 'string'],
            'body' => ['required', 'string', 'max:'.config('snack.messages.max_length')],
            'reply_to' => ['nullable', 'integer'],
            'agent_context' => ['nullable', 'array:kind,task_reference'],
            'agent_context.kind' => ['required_with:agent_context', Rule::in(self::AGENT_KINDS)],
            'agent_context.task_reference' => ['required_with:agent_context', 'string', 'max:30'],
            'attachments' => ['nullable', 'array', 'max:'.StoreAgentImages::MAX_IMAGES],
            'attachments.*' => ['array:filename,mime_type,data'],
            'attachments.*.filename' => ['required', 'string', 'max:180'],
            'attachments.*.mime_type' => ['required', 'string', Rule::in(StoreAgentImages::MIME_TYPES)],
            'attachments.*.data' => ['required', 'string'],
        ], [
            'body.required' => 'A message cannot be empty.',
            'body.max' => 'That message is too long to send.',
        ]);

        $agentContext = $this->agentContext($context, $request->get('agent_context'));

        if ($agentContext instanceof Response) {
            return $agentContext;
        }

        /** @var list<array{filename: string, mime_type: string, data: string}> $images */
        $images = array_values((array) ($request->get('attachments') ?? []));

        if ($images !== [] && ! app(ActorContext::class)->current()->isAgentFor($context->user)) {
            return Response::error('attachments are only accepted from an agent connector.');
        }

        if ($images !== [] && $request->get('reply_to') === null) {
            return Response::error('attachments can only be posted as a reply in a thread.');
        }

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

        $attachments = new Collection;

        if ($images !== []) {
            $limiterKey = 'agent-images:'.$context->user->id;

            if (RateLimiter::remaining($limiterKey, self::IMAGES_PER_MINUTE) < count($images)) {
                return Response::error('Too many images were attached recently; try again in a minute.');
            }

            try {
                $attachments = $storeImages->handle($channel->workspace, $context->user, $images);
            } catch (InvalidArgumentException $exception) {
                return Response::error($exception->getMessage());
            }

            RateLimiter::increment($limiterKey, 60, count($images));
        }

        try {
            $message = $sendMessage->handle(
                container: $channel,
                sender: $context->user,
                body: (string) $request->get('body'),
                parent: $parent,
                attachments: $attachments,
                agentContext: $agentContext,
            );
        } catch (PDOException $exception) {
            return $this->busy($exception);
        }

        return Response::text($parent !== null
            ? "Replied in the thread on message #{$parent->threadRootId()} in #{$channel->name} (message #{$message->id})."
            : "Posted message #{$message->id} in #{$channel->name}.");
    }

    /**
     * What an agent's post is for — its thread start, a plan, a reply or a
     * notice — and which task it belongs to, so the thread can offer that
     * task's assignee Approve and Cancel on the plan. Only an agent connector
     * may say so, and only about a task its owner can see.
     *
     * @return array{kind: string, task_id: int, task_reference: string}|Response|null
     */
    private function agentContext(BiteContext $context, mixed $given): array|Response|null
    {
        if (! is_array($given)) {
            return null;
        }

        if (! app(ActorContext::class)->current()->isAgentFor($context->user)) {
            return Response::error('agent_context is only accepted from an agent connector.');
        }

        $task = $this->task($context, $given['task_reference'] ?? null);

        if ($task === null) {
            return Response::error('There is no task with that reference that this person can see.');
        }

        return ['kind' => (string) $given['kind'], 'task_id' => $task->id, 'task_reference' => $task->reference()];
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
            'agent_context' => $schema->object([
                'kind' => $schema->string()->enum(self::AGENT_KINDS)->required(),
                'task_reference' => $schema->string()->description('The task this post is about, such as ENG-42.')->required(),
            ])->description('Agent connectors only: what this post is and which task it belongs to.'),
            'attachments' => $schema->array()
                ->items($schema->object([
                    'filename' => $schema->string()->required(),
                    'mime_type' => $schema->string()->enum(StoreAgentImages::MIME_TYPES)->required(),
                    'data' => $schema->string()->description('The image, base64-encoded; at most 5 MB.')->required(),
                ]))
                ->max(StoreAgentImages::MAX_IMAGES)
                ->description('Agent connectors only, in a thread reply: up to 6 PNG or JPEG screenshots.'),
        ];
    }
}
