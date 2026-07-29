<?php

namespace App\Actions\Messages;

use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageBody;
use Illuminate\Support\Facades\DB;

/**
 * Posts a message to a channel.
 *
 * Authorization is the caller's responsibility. The channel and sender arrive
 * as resolved models, never as client-supplied identifiers, so there is no way
 * for a request to post into a channel it was not authorized against.
 */
class SendMessage
{
    public function handle(Channel $channel, User $sender, string $body): Message
    {
        $content = MessageBody::fromInput($body);

        $message = DB::transaction(function () use ($channel, $sender, $content): Message {
            $message = Message::create([
                ...$content->toAttributes(),

                // Taken from the channel, not from the request: a message must
                // never be able to name a workspace other than its container's.
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'sender_id' => $sender->id,
                'type' => $channel->type->requiresElevatedPostingRights()
                    ? MessageType::Announcement
                    : MessageType::Text,
                'metadata' => [],
            ]);

            /*
             * Denormalized channel activity, updated with an atomic increment
             * rather than a read-modify-write. Two people posting at the same
             * instant would otherwise both read the same count and write the
             * same value back, losing one of the messages from the tally.
             */
            $channel->newQuery()->whereKey($channel->id)->update([
                'message_count' => DB::raw('message_count + 1'),
                'last_message_at' => $message->created_at,
            ]);

            $channel->forceFill([
                'message_count' => $channel->message_count + 1,
                'last_message_at' => $message->created_at,
            ])->syncOriginalAttributes(['message_count', 'last_message_at']);

            return $message;
        });

        // Loaded before broadcasting so the payload carries the author rather
        // than triggering a lazy load inside the queued broadcast worker.
        $message->setRelation('sender', $sender);

        MessageSent::dispatch($message);

        return $message;
    }
}
