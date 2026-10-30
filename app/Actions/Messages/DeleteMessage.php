<?php

namespace App\Actions\Messages;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Events\MessageDeleted;
use App\Events\ThreadActivity;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\Outbound\AgentChannelMessages;
use Illuminate\Support\Facades\DB;

/**
 * Removes a message.
 *
 * Soft delete rather than a hard one: thread replies must not be orphaned by
 * the removal of their parent, and a moderation decision needs the original row
 * to remain inspectable.
 */
class DeleteMessage
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly AgentChannelMessages $agentChannels,
    ) {}

    public function handle(Message $message, User $actor): void
    {
        $moderated = ! $message->wasSentBy($actor);

        // Locked before the first write when the deletion may be exported —
        // see AgentChannelMessages.
        $deleted = $this->agentChannels->transaction($message->workspace_id, $message->channel_id, $this->agentChannels->reportable($message), function () use ($message, $actor, $moderated): bool {
            /*
             * Locked and read again, so a second delete of the same message —
             * a double click, two tabs — finds it gone and does nothing: no
             * second tally decrement, no second event, no second audit entry.
             */
            if (Message::query()->whereKey($message->id)->lockForUpdate()->doesntExist()) {
                return false;
            }

            $message->delete();

            /*
             * A reply was never counted in the channel's tally — SendMessage
             * treats it as thread activity — so removing one must not decrement
             * a count it never incremented.
             */
            if ($message->channel_id !== null && ! $message->isThreadReply()) {
                // Guarded so a double-delete cannot drive the tally negative.
                Channel::query()
                    ->whereKey($message->channel_id)
                    ->where('message_count', '>', 0)
                    ->update(['message_count' => DB::raw('message_count - 1')]);
            }

            if ($message->isThreadReply()) {
                $this->releaseFromThread($message);
            }

            $this->agentChannels->deleted($message);

            /*
             * Only an admin removing someone else's message is audited. Logging
             * self-deletions too would bury the moderation decisions — the ones
             * anybody would ever go looking for — under ordinary typo cleanup.
             */
            if ($moderated) {
                $this->audit->handle(
                    workspace: $message->workspace,
                    action: AuditAction::MessageDeletedByAdmin,
                    actor: $actor,
                    auditable: $message,
                    oldValues: [
                        'sender_id' => $message->sender_id,
                        'channel_id' => $message->channel_id,
                        'preview' => $message->preview(),
                    ],
                );
            }

            return true;
        }, threadRootId: $message->threadRootId());

        if (! $deleted) {
            return;
        }

        MessageDeleted::dispatch($message);

        /*
         * The container still shows the parent with a reply count on it, and
         * that count just changed. Same event a new reply fires, for the same
         * audience — see App\Events\ThreadActivity.
         */
        if ($message->isThreadReply()) {
            $root = Message::query()->find($message->parent_message_id);

            if ($root !== null) {
                ThreadActivity::dispatch($root);
            }
        }
    }

    /**
     * Take a deleted reply back out of its thread's denormalized counters.
     *
     * `last_reply_id` is recomputed rather than decremented, because the reply
     * being removed may well be the newest one — leaving the pointer on a
     * deleted row would badge the thread as unread for every follower with no
     * way to clear it, since the reply they would have to read is gone.
     */
    private function releaseFromThread(Message $reply): void
    {
        DB::update(<<<'SQL'
            UPDATE messages AS root
            SET reply_count = GREATEST(root.reply_count - 1, 0),
                last_reply_id = newest.id,
                last_reply_at = newest.created_at
            FROM (
                SELECT MAX(id) AS id, MAX(created_at) AS created_at
                FROM messages
                WHERE parent_message_id = ? AND deleted_at IS NULL
            ) AS newest
            WHERE root.id = ?
        SQL, [$reply->parent_message_id, $reply->parent_message_id]);
    }
}
