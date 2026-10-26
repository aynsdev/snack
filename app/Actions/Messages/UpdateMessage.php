<?php

namespace App\Actions\Messages;

use App\Events\MessageUpdated;
use App\Jobs\IndexMessage;
use App\Models\Message;
use App\Services\MessageBody;
use App\Services\Outbound\AgentChannelMessages;

/**
 * Rewrites a message's body.
 *
 * `edited_at` is what the UI reads to show the "edited" marker, so it is set
 * here rather than inferred from `updated_at` — that column also moves for
 * reply counts and other bookkeeping the author had nothing to do with.
 */
class UpdateMessage
{
    public function __construct(
        private readonly SyncMentions $mentions,
        private readonly AgentChannelMessages $agentChannels,
    ) {}

    public function handle(Message $message, string $body): Message
    {
        $content = MessageBody::fromInput($body);

        // Re-saving identical text should not stamp the message as edited.
        if ($content->source === $message->body) {
            return $message;
        }

        // A transaction so the agent channel's outbox row commits with the edit
        // it describes — see AgentChannelMessages.
        $edited = $this->agentChannels->transaction($message->workspace_id, $message->channel_id, $this->agentChannels->reportable($message), function () use ($message, $content): bool {
            /*
             * Locked and read again: a message deleted since this request
             * loaded it stays deleted. Saving the stale copy would write the
             * new text onto the deleted row and send it to the dispatcher.
             */
            if (Message::query()->whereKey($message->id)->lockForUpdate()->doesntExist()) {
                return false;
            }

            $message->forceFill([
                ...$content->toAttributes(),
                'edited_at' => now(),
            ])->save();

            $this->agentChannels->updated($message);

            return true;
        }, threadRootId: $message->threadRootId());

        if (! $edited) {
            return $message;
        }

        /*
         * Re-synced rather than left alone: adding a handle in an edit has to
         * notify the new person, and removing one has to take their badge back.
         * A mention that survives the edit keeps its `read_at`, so fixing a typo
         * does not re-badge everyone who had already read it.
         *
         * The sender is the authority for what the edit may address — a message
         * cannot gain the right to @everyone by being edited by somebody else,
         * and only its author can edit it in the first place.
         */
        if ($message->sender !== null) {
            $this->mentions->handle($message, $message->sender);
        }

        // The stored vector is now stale by exactly the words that changed, so
        // it is rebuilt rather than patched.
        IndexMessage::dispatch($message->id);

        MessageUpdated::dispatch($message->loadAgentTask());

        return $message;
    }
}
