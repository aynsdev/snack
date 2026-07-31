<?php

namespace App\Actions\Messages;

use App\Events\MessageUpdated;
use App\Models\Message;
use App\Services\MessageBody;

/**
 * Rewrites a message's body.
 *
 * `edited_at` is what the UI reads to show the "edited" marker, so it is set
 * here rather than inferred from `updated_at` — that column also moves for
 * reply counts and other bookkeeping the author had nothing to do with.
 */
class UpdateMessage
{
    public function __construct(private readonly SyncMentions $mentions) {}

    public function handle(Message $message, string $body): Message
    {
        $content = MessageBody::fromInput($body);

        // Re-saving identical text should not stamp the message as edited.
        if ($content->source === $message->body) {
            return $message;
        }

        $message->forceFill([
            ...$content->toAttributes(),
            'edited_at' => now(),
        ])->save();

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

        MessageUpdated::dispatch($message);

        return $message;
    }
}
