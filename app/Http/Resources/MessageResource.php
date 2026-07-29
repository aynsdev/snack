<?php

namespace App\Http\Resources;

use App\Models\Message;
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
            ],
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
            'conversation_id' => $message->conversation_id,
            'parent_message_id' => $message->parent_message_id,
            'body' => $message->body,
            'type' => $message->type->value,
            'sender' => $message->sender !== null
                ? (new UserResource($message->sender))->resolve()
                : null,
            'reply_count' => $message->reply_count,
            'last_reply_at' => $message->last_reply_at?->toIso8601String(),
            'has_attachments' => $message->has_attachments,
            'is_edited' => $message->isEdited(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
