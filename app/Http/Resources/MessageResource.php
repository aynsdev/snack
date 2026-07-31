<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageReaction;
use App\Services\MessageBody;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message as delivered to the client.
 *
 * `body` is the author's source text, never HTML — see {@see MessageBody}
 * for why. The client tokenizes it into React elements, so nothing here is ever
 * interpreted as markup.
 *
 * Mirrors the `Message` type in resources/js/types/snack.ts.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            ...self::broadcastPayload($this->resource),

            /*
             * What *this* viewer may do with the message, resolved through the
             * policy rather than guessed from the sender id — the policy also
             * accounts for the edit window, archived channels, and the admin
             * moderation path, none of which the client can see.
             *
             * These flags decide which controls render. Every mutation is
             * re-authorized when it arrives.
             */
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
                'pin' => $user?->can('pin', $this->resource) ?? false,
            ],

            /*
             * Viewer-specific, and therefore outside broadcastPayload: a save is
             * a private bookmark, so "is this saved" has a different answer for
             * every subscriber and no single answer a broadcast could carry.
             */
            'is_saved' => $this->resource->relationLoaded('saves')
                ? $this->resource->saves->isNotEmpty()
                : false,
        ];
    }

    /**
     * The viewer-independent shape of a message.
     *
     * Shared with the broadcast events so an HTTP-loaded message and a
     * live-arriving one are the same object to the client. If they diverged,
     * a message would subtly change appearance the moment the page reloaded.
     *
     * @return array<string, mixed>
     */
    public static function broadcastPayload(Message $message): array
    {
        return [
            'id' => $message->id,
            'workspace_id' => $message->workspace_id,
            'channel_id' => $message->channel_id,
            'conversation_id' => $message->conversation?->public_id,
            'parent_message_id' => $message->parent_message_id,
            'body' => $message->body,
            'type' => $message->type->value,
            'sender' => $message->sender !== null
                ? (new UserResource($message->sender))->resolve()
                : null,
            'reply_count' => $message->reply_count,
            'last_reply_at' => $message->last_reply_at?->toIso8601String(),
            'last_reply_id' => $message->last_reply_id,
            'reactions' => self::reactionSummary($message),

            /*
             * Shared state, so it belongs here rather than alongside `is_saved`:
             * a pin looks the same to everyone in the container, which is what
             * lets one broadcast carry it.
             */
            'is_pinned' => $message->relationLoaded('pin')
                ? $message->pin !== null
                : false,
            'has_attachments' => $message->has_attachments,
            'attachments' => self::attachments($message),
            'is_edited' => $message->isEdited(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * The files riding along with this message.
     *
     * `has_attachments` stays in the payload alongside this list because they
     * answer different questions at different costs: the boolean is on the row
     * itself and is what a client checks before deciding to render anything,
     * while this array requires the relation to have been loaded. Every path
     * that draws a message loads it through
     * {@see Message::scopeWithTimelineRelations()}.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function attachments(Message $message): array
    {
        if (! $message->relationLoaded('attachments')) {
            return [];
        }

        return $message->attachments
            ->map(fn (MessageAttachment $attachment): array => AttachmentResource::payload($attachment))
            ->values()
            ->all();
    }

    /**
     * Reactions grouped by emoji, newest group last.
     *
     * # Why `user_ids` and not `reacted`
     *
     * This shape is shared with the broadcast, and a broadcast reaches every
     * subscriber at once — there is no single viewer to compute "did *you*
     * react" for. Sending the ids and letting the client find itself in them
     * keeps one shape for both paths, which is what stops a reaction from
     * changing appearance the moment the page reloads.
     *
     * The id list is capped: past a dozen names the tooltip is unreadable
     * anyway, and the payload must not grow with the size of the channel.
     * `count` stays exact regardless.
     *
     * Returns nothing when the relation was not loaded. Every path that renders
     * a message loads it through {@see Message::scopeWithTimelineRelations()};
     * a freshly created message genuinely has none.
     *
     * @return array<int, array{emoji: string, count: int, user_ids: array<int, int>, names: array<int, string>}>
     */
    public static function reactionSummary(Message $message): array
    {
        if (! $message->relationLoaded('reactions')) {
            return [];
        }

        $limit = (int) config('snack.reactions.named_reactors', 12);

        return $message->reactions
            ->groupBy('emoji')
            ->map(fn ($group, string $emoji): array => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'user_ids' => $group->take($limit)->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all(),
                // The reactor always exists: `user_id` is a non-null foreign key
                // that cascades, so a deleted account takes its reactions with it.
                'names' => $group->take($limit)
                    ->map(fn (MessageReaction $reaction): string => $reaction->user->name)
                    ->values()
                    ->all(),
            ])
            // Ordered by when each emoji first appeared, so a pill does not
            // jump position every time somebody adds to it.
            ->sortBy(fn (array $summary, string $emoji): int => $message->reactions
                ->where('emoji', $emoji)
                ->min('id') ?? 0)
            ->values()
            ->all();
    }
}
