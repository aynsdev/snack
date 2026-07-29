<?php

namespace App\Actions\Messages;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Events\MessageDeleted;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
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
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(Message $message, User $actor): void
    {
        $moderated = ! $message->wasSentBy($actor);

        DB::transaction(function () use ($message, $actor, $moderated): void {
            $message->delete();

            if ($message->channel_id !== null) {
                // Guarded so a double-delete cannot drive the tally negative.
                Channel::query()
                    ->whereKey($message->channel_id)
                    ->where('message_count', '>', 0)
                    ->update(['message_count' => DB::raw('message_count - 1')]);
            }

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
        });

        MessageDeleted::dispatch($message);
    }
}
