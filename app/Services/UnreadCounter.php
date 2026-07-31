<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Unread badge counts for the sidebar.
 *
 * This runs on every request inside a workspace, so it is deliberately one
 * query for all channels rather than one per channel. It joins the user's
 * memberships to the message table and counts what sits above each read
 * pointer — an index range scan per channel, bounded by the number of unread
 * messages rather than by the length of the history.
 *
 * Nothing here stores a row per user per message. The only per-user state is
 * the pointer already on the membership row.
 */
class UnreadCounter
{
    /**
     * Unread counts keyed by channel id, for every channel the user has joined.
     *
     * Channels with nothing unread are omitted, so the payload stays
     * proportional to what actually needs a badge rather than to the number of
     * channels the user is in.
     *
     * @return array<int, array{messages: int, mentions: int}>
     */
    public function forChannels(Workspace $workspace, User $user): array
    {
        /*
         * `sender_id <> user` because your own message is not unread to you —
         * without it every channel would light up the instant you posted in it.
         *
         * `parent_message_id IS NULL` keeps thread replies out of the channel
         * badge; threads carry their own unread state, added in Phase 4.
         *
         * COALESCE handles a membership that has never been read: every message
         * in the channel is above a pointer of zero.
         */
        $rows = DB::table('channel_members')
            ->join('channels', 'channels.id', '=', 'channel_members.channel_id')
            ->join('messages', function ($join) {
                $join->on('messages.channel_id', '=', 'channel_members.channel_id')
                    ->whereRaw('messages.id > COALESCE(channel_members.last_read_message_id, 0)')
                    ->whereNull('messages.deleted_at')
                    ->whereNull('messages.parent_message_id');
            })
            ->where('channel_members.user_id', $user->id)
            ->where('channels.workspace_id', $workspace->id)
            ->where('channels.is_archived', false)
            // Snack's own notices ("Ada added Bo") have no sender and must not
            // make a room look like somebody said something.
            ->whereNotNull('messages.sender_id')
            ->whereRaw('messages.sender_id IS DISTINCT FROM ?', [$user->id])
            ->groupBy('channel_members.channel_id')
            ->select([
                'channel_members.channel_id',
                DB::raw('COUNT(*) AS message_count'),
            ])
            ->get();

        $mentions = $this->channelMentions($workspace, $user);

        $counts = [];

        foreach ($rows as $row) {
            $channelId = (int) $row->channel_id;

            $counts[$channelId] = [
                'messages' => (int) $row->message_count,
                'mentions' => $mentions[$channelId] ?? 0,
            ];
        }

        /*
         * A channel can hold an unread mention with no unread messages: the
         * reader advanced their pointer past it without opening the channel — a
         * "mark all read" — and the mention row survives that deliberately,
         * because being addressed by name outlives a bulk dismissal.
         */
        foreach ($mentions as $channelId => $count) {
            $counts[$channelId] ??= ['messages' => 0, 'mentions' => $count];
        }

        return $counts;
    }

    /**
     * Unread mention counts per channel.
     *
     * Reads the materialized `mentions` rows rather than searching message
     * bodies — that is the entire reason the rows exist. `mentions_unread_index`
     * is partial on `read_at IS NULL`, so this scans only what is actually
     * unread for this person rather than their whole mention history.
     *
     * @return array<int, int>
     */
    private function channelMentions(Workspace $workspace, User $user): array
    {
        $rows = DB::table('mentions')
            ->join('messages', 'messages.id', '=', 'mentions.message_id')
            ->where('mentions.mentioned_user_id', $user->id)
            ->where('mentions.workspace_id', $workspace->id)
            ->whereNull('mentions.read_at')
            ->whereNull('messages.deleted_at')
            ->whereNotNull('messages.channel_id')
            ->groupBy('messages.channel_id')
            ->select([
                'messages.channel_id',
                DB::raw('COUNT(*) AS mention_count'),
            ])
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->channel_id] = (int) $row->mention_count;
        }

        return $counts;
    }

    /**
     * Every unread mention for this user in this workspace, across containers.
     *
     * Backs the global "Mentions" badge, which is a single number rather than a
     * per-container breakdown — so this is one indexed count, not a sum over the
     * per-channel map.
     */
    public function mentionCount(Workspace $workspace, User $user): int
    {
        return DB::table('mentions')
            ->join('messages', 'messages.id', '=', 'mentions.message_id')
            ->where('mentions.mentioned_user_id', $user->id)
            ->where('mentions.workspace_id', $workspace->id)
            ->whereNull('mentions.read_at')
            ->whereNull('messages.deleted_at')
            ->count();
    }

    /**
     * How many followed threads have replies the user has not read.
     *
     * Threads, not replies: the badge answers "how many conversations are
     * waiting on you", which is the question the sidebar entry is asking. A
     * count of individual replies would be dominated by whichever thread happens
     * to be busiest and would say nothing about how many things need attention.
     *
     * The comparison is pointer-against-pointer — `messages.last_reply_id`, kept
     * current by SendMessage, against the subscriber's `last_read_reply_id` —
     * so this stays one index scan over the user's subscriptions no matter how
     * long any thread is. Nothing here stores a row per user per reply.
     */
    public function threadCount(Workspace $workspace, User $user): int
    {
        return DB::table('thread_subscriptions')
            ->join('messages', 'messages.id', '=', 'thread_subscriptions.root_message_id')
            ->where('thread_subscriptions.user_id', $user->id)
            ->where('messages.workspace_id', $workspace->id)
            ->whereNull('messages.deleted_at')
            ->whereNotNull('messages.last_reply_id')
            ->whereRaw('messages.last_reply_id > COALESCE(thread_subscriptions.last_read_reply_id, 0)')
            ->count();
    }

    /**
     * Unread counts keyed by conversation id, for every conversation the user is
     * an active participant in.
     *
     * Structurally identical to {@see forChannels()} — one query, one index range
     * scan per conversation, no row per user per message — because the read model
     * is the same: a pointer on the participant row.
     *
     * A DM has no "unread but not mentioned" state to distinguish. Every message
     * in a conversation you are in is addressed to you, so the mention count
     * tracks the message count rather than staying at zero until Phase 4; the
     * sidebar can then treat a DM as loudly as it treats a real mention.
     *
     * Keyed by the conversation's opaque public id, not its primary key.
     *
     * @return array<string, array{messages: int, mentions: int}>
     */
    public function forConversations(Workspace $workspace, User $user): array
    {
        $rows = DB::table('conversation_participants')
            ->join('conversations', 'conversations.id', '=', 'conversation_participants.conversation_id')
            ->join('messages', function ($join) {
                $join->on('messages.conversation_id', '=', 'conversation_participants.conversation_id')
                    ->whereRaw('messages.id > COALESCE(conversation_participants.last_read_message_id, 0)')
                    ->whereNull('messages.deleted_at')
                    ->whereNull('messages.parent_message_id');
            })
            ->where('conversation_participants.user_id', $user->id)
            // Someone who left keeps read access to the history but should not
            // be badged about a thread they walked away from.
            ->whereNull('conversation_participants.left_at')
            ->where('conversations.workspace_id', $workspace->id)
            ->whereNotNull('messages.sender_id')
            ->whereRaw('messages.sender_id IS DISTINCT FROM ?', [$user->id])
            ->groupBy('conversations.public_id')
            ->select([
                // Keyed by the opaque id so the sidebar can look a count up by
                // the same identifier it renders links with.
                'conversations.public_id',
                DB::raw('COUNT(*) AS message_count'),
            ])
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $count = (int) $row->message_count;

            $counts[(string) $row->public_id] = [
                'messages' => $count,
                'mentions' => $count,
            ];
        }

        return $counts;
    }
}
