<?php

namespace App\Http\Resources;

use App\Models\Channel;
use App\Models\Conversation;
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
        return self::forContainer(
            $message->isInChannel() ? $message->channel : $message->conversation,
        );
    }

    /**
     * The same summary for anything else that hangs off a container — a huddle,
     * whose history has to say where each call happened.
     *
     * @return array<string, mixed>|null
     */
    public static function forContainer(Channel|Conversation|null $container): ?array
    {
        if ($container instanceof Channel) {
            return [
                'kind' => 'channel',
                'id' => $container->id,
                'name' => $container->name,
                'slug' => $container->slug,
                'type' => $container->type->value,
            ];
        }

        return $container === null ? null : [
            'kind' => 'conversation',
            // The opaque public id, never the primary key.
            'id' => $container->public_id,
            'type' => $container->type->value,
        ];
    }
}
