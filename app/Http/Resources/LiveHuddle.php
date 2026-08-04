<?php

namespace App\Http\Resources;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Huddle;
use Illuminate\Http\Request;

/**
 * The huddle currently running in a container, if there is one.
 *
 * Ships with the channel and conversation pages rather than being fetched after
 * they paint, for the reason pinned messages do: a huddle bar that appears a
 * beat late is a row of content pushing the conversation down just as somebody
 * starts reading it. Unlike pins it is also *time-sensitive* — the whole point
 * of the bar is to catch someone before the call ends.
 *
 * Null is the overwhelmingly common answer, and costs one indexed lookup against
 * a partial index over live huddles only.
 *
 * A sibling of {@see ContainerSummary}: both are small static shapes that a page
 * needs alongside its main payload and that would be awkward as a full resource.
 */
final class LiveHuddle
{
    /**
     * @return array<string, mixed>|null
     */
    public static function for(Channel|Conversation $container, Request $request): ?array
    {
        $huddle = Huddle::query()
            ->live()
            ->forContainer($container)
            /*
             * The container is loaded because HuddleResource names it, and the
             * roster with its users because the bar draws an avatar stack. Both
             * eager, so a page with a live huddle does not pay a query per face.
             */
            ->with(['channel', 'conversation', 'activeParticipants.user'])
            ->first();

        return $huddle === null
            ? null
            : (new HuddleResource($huddle))->resolve($request);
    }
}
