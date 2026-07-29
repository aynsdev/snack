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

        MessageUpdated::dispatch($message);

        return $message;
    }
}
