<?php

namespace App\Http\Resources;

use App\Models\Message;

/**
 * Just enough of a channel or conversation to render "in #design".
 *
 * Used by the views that list messages *out of context* — Threads, Mentions,
 * and later Saved and Search — each of which has to say where a message came
 * from without shipping the whole container.
 *
 * # Why a conversation carries no name
 *
 * A conversation's title is viewer-specific: an unnamed DM is named by whoever
 * else is in it, which is a different string for each participant. Putting a
 * name here would mean picking one viewer's answer and showing it to everyone.
 * The client derives its own from the sidebar payload it already holds, keyed by
 * the opaque public id below.
 */
final class ContainerSummary
{
    /**
     * @return array<string, mixed>|null
     */
    public static function for(Message $message): ?array
    {
        if ($message->isInChannel()) {
            $channel = $message->channel;

            return $channel === null ? null : [
                'kind' => 'channel',
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'type' => $channel->type->value,
            ];
        }

        $conversation = $message->conversation;

        return $conversation === null ? null : [
            'kind' => 'conversation',
            // The opaque public id, never the primary key.
            'id' => $conversation->public_id,
            'type' => $conversation->type->value,
        ];
    }
}
