<?php

namespace App\Actions\Messages;

use App\Models\Message;
use App\Models\SavedMessage;
use App\Models\User;

/**
 * Saving and unsaving a message — a private bookmark.
 *
 * # Why nothing is broadcast
 *
 * Unlike a pin, a save is visible only to the person who made it. Telling the
 * container about it would leak what someone is keeping track of to everyone
 * else in the room, which is the opposite of what a private bookmark is for.
 * The only client that needs to know is the one that asked, and it gets the
 * answer in the response.
 *
 * Authorized as `save` on the message, which resolves to plain read access:
 * anything you may read, you may keep a pointer to.
 */
class ToggleSave
{
    public function save(Message $message, User $user): void
    {
        $now = now();

        // ON CONFLICT DO NOTHING against the unique (user_id, message_id): a
        // repeated save is the state the caller asked for.
        SavedMessage::query()->insertOrIgnore([
            'user_id' => $user->id,
            'message_id' => $message->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function unsave(Message $message, User $user): void
    {
        SavedMessage::query()
            ->where('user_id', $user->id)
            ->where('message_id', $message->id)
            ->delete();
    }

    public function isSaved(Message $message, User $user): bool
    {
        return SavedMessage::query()
            ->where('user_id', $user->id)
            ->where('message_id', $message->id)
            ->exists();
    }
}
