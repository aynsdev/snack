<?php

namespace App\Actions\Messages;

use App\Models\Message;
use App\Models\ThreadSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who follows a thread, and where each follower has read up to.
 *
 * # Why following is implicit
 *
 * Nobody subscribes to a thread on purpose. They reply to something, and from
 * then on they expect to hear about what comes back — so participation *is* the
 * subscription. Making it explicit would mean every useful thread starts silent
 * for the people actually in it.
 *
 * Rows are only ever created once a thread exists. The overwhelming majority of
 * messages never receive a reply, and giving each of them a subscription row on
 * the chance that one day it might would make this table larger than `messages`
 * for no benefit.
 */
class SubscribeToThread
{
    /**
     * Bring subscriptions up to date after a reply lands.
     *
     * Two people are involved: whoever just replied, and whoever wrote the
     * message being replied to. The replier is marked as having already read
     * their own reply — a thread that badges you for your own words is a bug
     * everyone notices immediately.
     */
    public function afterReply(int $rootId, Message $reply, User $replier): void
    {
        $this->follow($rootId, $replier->id, readThrough: $reply->id);

        $rootAuthorId = Message::query()->whereKey($rootId)->value('sender_id');

        /*
         * The root's author is pulled in the first time someone answers them,
         * not when they posted. They have not read the reply, so their pointer
         * stays where it is and the thread badges — which is the entire point
         * of answering someone.
         */
        if ($rootAuthorId !== null && (int) $rootAuthorId !== $replier->id) {
            $this->follow($rootId, (int) $rootAuthorId);
        }
    }

    /**
     * Idempotent follow.
     *
     * An upsert rather than a read-then-insert: two replies landing in the same
     * instant would otherwise both find no row and both try to create one, and
     * the unique index would turn the loser into a 500 on an otherwise fine
     * message.
     */
    public function follow(int $rootId, int $userId, ?int $readThrough = null): void
    {
        $now = now();

        DB::table('thread_subscriptions')->upsert(
            [[
                'user_id' => $userId,
                'root_message_id' => $rootId,
                'last_read_reply_id' => $readThrough,
                'last_read_at' => $readThrough === null ? null : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'root_message_id'],
            /*
             * On conflict the pointer may only move forward, and only if this
             * call carried one. Re-following must never rewind someone's place
             * in a thread they have already read.
             *
             * NULLIF undoes the COALESCE: a subscriber who has read nothing and
             * is re-followed with nothing keeps a NULL pointer rather than a 0,
             * which would violate the foreign key onto `messages`.
             *
             * The list is never empty, because an upsert with nothing to update
             * compiles to a plain INSERT and the unique index turns an ordinary
             * second reply into a 500.
             */
            [
                'last_read_reply_id' => DB::raw(
                    'NULLIF(GREATEST('
                    .'COALESCE(thread_subscriptions.last_read_reply_id, 0), '
                    .'COALESCE(EXCLUDED.last_read_reply_id, 0)'
                    .'), 0)'
                ),
                'last_read_at' => DB::raw('COALESCE(EXCLUDED.last_read_at, thread_subscriptions.last_read_at)'),
                'updated_at' => DB::raw('EXCLUDED.updated_at'),
            ],
        );
    }

    /**
     * Stop following. Deleting the row *is* the unfollow — see the migration.
     */
    public function unfollow(int $rootId, User $user): void
    {
        ThreadSubscription::query()
            ->where('root_message_id', $rootId)
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * Advance a follower's read pointer to the newest reply they have seen.
     *
     * Clamped forward only: read syncs arrive from several tabs at once and an
     * older one landing last must not un-read the thread.
     */
    public function markRead(int $rootId, User $user, int $lastReadReplyId): void
    {
        ThreadSubscription::query()
            ->where('root_message_id', $rootId)
            ->where('user_id', $user->id)
            ->whereRaw('COALESCE(last_read_reply_id, 0) < ?', [$lastReadReplyId])
            ->update([
                'last_read_reply_id' => $lastReadReplyId,
                'last_read_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
