<?php

namespace App\Services\Outbound;

use App\Enums\ActorVia;
use App\Enums\OutboundEventType;
use App\Models\AgentSummon;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\ActorContext;
use App\Services\MentionParser;
use Illuminate\Support\Facades\Gate;

/**
 * `@agent` in a channel: asks somebody's Kusina into the thread.
 *
 * Only a person typing in the web UI can summon, in a channel and never a
 * DM. `@renz @agent` asks Renz's agent; `@agent` alone asks the author's own.
 * Whoever is asked has to be an active member who can read the channel, or
 * nothing happens. The dispatcher hears `agent.summoned` once per thread and
 * person; from then on {@see AgentChannelMessages} exports the thread until
 * its root is deleted.
 */
class AgentSummons
{
    public function __construct(
        private readonly OutboundEvents $events,
        private readonly OutboundPayloads $payloads,
        private readonly ActorContext $actors,
    ) {}

    /**
     * Whether a message about to be sent might summon — decided before it is
     * written, so the transaction can take the workspace lock first.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function mayBeSummons(User $sender, string $source, array $metadata): bool
    {
        return ! isset($metadata['agent'])
            && ! $sender->isBot()
            && $this->actors->current()->via === ActorVia::Web
            && $this->events->enabled()
            && MentionParser::agentSummons($source)['summoned'];
    }

    /**
     * Record the summons a just-written message makes, inside the transaction
     * that wrote it.
     */
    public function record(Channel $channel, Message $message, User $sender): void
    {
        if (! $this->events->locked($message->workspace_id)) {
            return;
        }

        $target = $this->target($channel, $sender, MentionParser::agentSummons($message->body)['target']);

        if ($target === null) {
            return;
        }

        $rootId = $message->threadRootId();

        $existing = AgentSummon::query()
            ->where('thread_root_id', $rootId)
            ->where('target_user_id', $target->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null && $existing->closed_at === null) {
            return;
        }

        $attributes = ['summoner_id' => $sender->id, 'message_id' => $message->id, 'closed_at' => null];

        if ($existing !== null) {
            $existing->update($attributes);
        } else {
            AgentSummon::create([
                ...$attributes,
                'workspace_id' => $message->workspace_id,
                'channel_id' => $channel->id,
                'thread_root_id' => $rootId,
                'target_user_id' => $target->id,
            ]);
        }

        $this->events->defer(OutboundEventType::AgentSummoned, $message->workspace_id, function () use ($message, $channel, $rootId, $sender, $target): array {
            $current = Message::query()->with('sender')->find($message->id);
            $live = Channel::query()->find($channel->id);

            if ($current === null || $live === null || $live->is_archived) {
                return [];
            }

            return [[
                'channel' => $this->payloads->channel($live),
                'message' => $this->payloads->message($current),
                'thread_root_id' => $rootId,
                'summoner_id' => $sender->id,
                'target_user_id' => $target->id,
            ]];
        });
    }

    private function target(Channel $channel, User $sender, ?string $handle): ?User
    {
        $target = $handle === null ? $sender : User::query()->where('username', $handle)->first();

        if ($target === null || $target->isBot() || $target->roleIn($channel->workspace) === null) {
            return null;
        }

        return Gate::forUser($target)->allows('view', $channel) ? $target : null;
    }
}
