<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation as seen by one participant.
 *
 * Almost everything here is viewer-specific. An unnamed direct conversation has
 * no title of its own — it is titled by *the other person*, which is a different
 * string for each side — and the mute flag and read pointer live on the viewer's
 * participant row. That is why no broadcast carries this shape: one payload
 * cannot be correct for two recipients.
 *
 * Callers are expected to have eager-loaded `activeUsers` and a `participants`
 * relation constrained to the viewer. Resolving either here would be an N+1
 * across the whole DM list.
 *
 * Mirrors the `Conversation` type in resources/js/types/snack.ts.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $participant = $user !== null ? $this->participantFor($user) : null;

        return [
            // The opaque identifier. The primary key never leaves the server.
            'id' => $this->public_id,
            'type' => $this->type->value,
            'name' => $this->name,
            'display_name' => $user !== null ? $this->displayNameFor($user) : ($this->name ?? 'Conversation'),

            /*
             * The whole roster, viewer included. The sidebar needs the other
             * people to render avatars; the conversation header needs to know
             * how many people are in the room, which includes you.
             */
            'participants' => $this->activeUsers
                ->map(fn (User $participantUser): array => (new UserResource($participantUser))->resolve($request))
                ->all(),

            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'is_muted' => $participant->is_muted ?? false,
            'notification_level' => $participant?->notification_level->value,
            'last_read_message_id' => $participant?->last_read_message_id,

            // Someone who left a group keeps read access to the history they
            // were part of, so the client has to know not to render a composer.
            'has_left' => $participant?->hasLeft() ?? false,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
