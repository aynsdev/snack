<?php

namespace App\Actions\Messages;

use App\Events\ReactionChanged;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;

/**
 * Adding and removing an emoji reaction.
 *
 * Authorization is the caller's responsibility; the message arrives as a
 * resolved model, never as a client-supplied id.
 *
 * # Why these are idempotent rather than a toggle
 *
 * A single "toggle" endpoint sounds simpler and is worse: reactions are the one
 * control people double-click, and two clicks racing each other against a
 * toggle can land in either order and leave the reaction in the state nobody
 * asked for. "Add" and "remove" each describe an end state, so repeating one —
 * or applying two out of order — converges instead of flapping.
 */
class ToggleReaction
{
    public function add(Message $message, User $user, string $emoji): void
    {
        $now = now();

        /*
         * ON CONFLICT DO NOTHING, letting the unique index on
         * (message_id, user_id, emoji) be the authority on "one of each per
         * person". The double-click case then costs nothing and reports zero
         * rows, which is also the signal for whether anyone needs telling.
         *
         * Deliberately not a check-then-insert: that leaves the same race open,
         * just narrower. Deliberately not a caught unique violation either —
         * under Postgres a failed statement aborts the whole surrounding
         * transaction, so the "harmless" duplicate would take the request's
         * other work down with it.
         */
        $inserted = MessageReaction::query()->insertOrIgnore([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'emoji' => $emoji,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            $this->broadcast($message);
        }
    }

    public function remove(Message $message, User $user, string $emoji): void
    {
        $deleted = MessageReaction::query()
            ->where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->delete();

        // Nothing removed means it was already gone; no one needs telling.
        if ($deleted > 0) {
            $this->broadcast($message);
        }
    }

    /**
     * Re-read the reactions and publish the whole summary.
     *
     * The event carries the message's *complete* reaction state rather than a
     * "+1 taco" delta. A client that missed one broadcast — a reconnect, a
     * backgrounded tab — would carry that drift forever under a delta, and the
     * only way back would be a reload. A full summary is self-correcting, and
     * it is small: a handful of shortcodes and a capped list of ids.
     */
    private function broadcast(Message $message): void
    {
        $message->load('reactions.user:id,name');

        ReactionChanged::dispatch($message);
    }
}
