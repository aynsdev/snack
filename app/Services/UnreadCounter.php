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

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->channel_id] = [
                'messages' => (int) $row->message_count,
                // Mention rows are written in Phase 4, when @-mentions are
                // parsed out of a message body. Until then a channel can be
                // unread but never "mentioned".
                'mentions' => 0,
            ];
        }

        return $counts;
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
