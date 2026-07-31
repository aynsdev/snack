<?php

namespace App\Actions\Messages;

use App\Events\PinChanged;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PinnedMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pinning and unpinning a message.
 *
 * Authorization is the caller's responsibility; the message arrives as a
 * resolved model, never as a client-supplied id.
 *
 * # Shared, unlike a save
 *
 * A pin is visible to everyone in the container and can be removed by anyone
 * who could have made it — it is a property of the conversation, not of the
 * person who clicked. That is why the container is denormalized onto the row
 * (making "what is pinned here" one index scan) and why the unique index is on
 * `message_id` alone: a message is either pinned or it is not.
 *
 * Both operations are idempotent for the same reason reactions are — see
 * {@see ToggleReaction} — and report whether anything actually changed, which
 * is what decides whether the container needs telling.
 */
class TogglePin
{
    public function pin(Message $message, User $actor): void
    {
        $now = now();

        // ON CONFLICT DO NOTHING: the unique index on `message_id` is the
        // authority, so a second pin is the state the caller asked for rather
        // than a 500 — and under Postgres a caught violation would abort the
        // surrounding transaction anyway.
        $inserted = PinnedMessage::query()->insertOrIgnore([
            'message_id' => $message->id,
            // Taken from the message, not the request: a pin must never be able
            // to name a container other than its message's own.
            'channel_id' => $message->channel_id,
            'conversation_id' => $message->conversation_id,
            'pinned_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            PinChanged::dispatch($message, true);
        }
    }

    public function unpin(Message $message): void
    {
        $deleted = PinnedMessage::query()
            ->where('message_id', $message->id)
            ->delete();

        if ($deleted > 0) {
            PinChanged::dispatch($message, false);
        }
    }

    /**
     * Everything currently pinned in a container, newest pin first.
     *
     * @return Collection<int, Message>
     */
    public function pinnedIn(Channel|Conversation $container): Collection
    {
        $column = $container instanceof Channel ? 'channel_id' : 'conversation_id';

        return Message::query()
            ->whereHas('pin', fn ($query) => $query->where($column, $container->id))
            ->withTimelineRelations()
            // By the pin's recency, not the message's: the useful order is
            // "what was pinned last", which is often an old message.
            ->orderByDesc(
                PinnedMessage::query()
                    ->select('created_at')
                    ->whereColumn('pinned_messages.message_id', 'messages.id')
                    ->limit(1),
            )
            ->get();
    }
}
