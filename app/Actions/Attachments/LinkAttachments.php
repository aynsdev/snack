<?php

namespace App\Actions\Attachments;

use App\Http\Requests\Concerns\ResolvesAttachments;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Collection;

/**
 * Claims uploaded-but-unattached files for the message that was just sent.
 *
 * The attachments arrive as resolved models rather than as client ids — see
 * {@see ResolvesAttachments}, which loads them
 * through the workspace *and* the uploader, so a request cannot claim somebody
 * else's upload or a file from another tenant.
 *
 * The `WHERE message_id IS NULL` below is the second half of that guarantee and
 * the reason this is one statement rather than a loop of saves: two requests
 * racing for the same upload — a double-clicked send button — must not both
 * succeed, or one message would end up pointing at a file the other also claims.
 */
class LinkAttachments
{
    /**
     * @param  Collection<int, MessageAttachment>  $attachments
     * @return int How many were actually claimed.
     */
    public function handle(Message $message, Collection $attachments): int
    {
        if ($attachments->isEmpty()) {
            return 0;
        }

        $claimed = MessageAttachment::query()
            ->whereIn('id', $attachments->pluck('id'))
            ->whereNull('message_id')
            ->where('workspace_id', $message->workspace_id)
            ->update(['message_id' => $message->id]);

        if ($claimed > 0) {
            /*
             * Denormalized onto the message because the timeline asks "does this
             * have files?" for every row it draws, and the answer must not cost
             * a join per message. Force-filled as well as written so the model
             * the caller goes on to broadcast already says yes.
             */
            $message->forceFill(['has_attachments' => true])->save();
        }

        return $claimed;
    }
}
