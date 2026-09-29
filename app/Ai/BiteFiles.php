<?php

namespace App\Ai;

use App\Models\Asset;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Services\MessageVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Turning a file id the model said back into bytes the asker is allowed to see.
 *
 * # Why this is not just `MessageAttachment::find()`
 *
 * The id arrives from the model, and the model got it from a tool result, and
 * a tool result is text a language model has been reading and rewriting. It is
 * therefore exactly as trustworthy as a URL somebody typed: it might be a file
 * from another workspace, or one in a private channel the asker was never in,
 * or one the model simply made up. Re-resolving it against the asker on every
 * use is what makes that harmless — the same rule the rest of Snack applies to
 * any identifier that did not come from the current request's authorization.
 *
 * The two paths mirror {@see App\Ai\Tools\FindFiles}, because a file that could
 * not be found must not become readable by guessing its id.
 */
final readonly class BiteFiles
{
    public function __construct(private MessageVisibility $visibility) {}

    /**
     * The attachment behind a file id, or null if this person may not have it.
     *
     * Null rather than an exception: "no such file" is an answer the model
     * should relay, not a job failure. It is deliberately the same answer for
     * a file that does not exist and one that exists but is not theirs, so that
     * probing ids cannot map out a private channel's attachments.
     */
    public function resolve(BiteContext $context, mixed $id): ?MessageAttachment
    {
        if (! is_numeric($id)) {
            return null;
        }

        $attachment = MessageAttachment::query()
            ->where('workspace_id', $context->workspace->id)
            ->whereKey((int) $id)
            ->first();

        if ($attachment === null) {
            return null;
        }

        return $this->readable($context, $attachment) ? $attachment : null;
    }

    /**
     * Whether this person may read these bytes.
     *
     * A file is readable three ways, and any is enough. It is on the asset
     * library's shelf, which every member but a guest can see. It is on a
     * project card they can open. Or it is attached to a message they can
     * read, which is the rule the file browser and search are both built on.
     */
    private function readable(BiteContext $context, MessageAttachment $attachment): bool
    {
        if ($attachment->asset_id !== null && ! $context->isGuest()) {
            $shelved = Asset::query()
                ->whereKey($attachment->asset_id)
                ->where('workspace_id', $context->workspace->id)
                ->exists();

            if ($shelved) {
                return true;
            }
        }

        /*
         * A file on a project card is exactly as visible as the card: whoever
         * may open the task may open what is attached to it.
         */
        if ($attachment->task_id !== null) {
            $task = Task::query()
                ->whereKey($attachment->task_id)
                ->whereHas('project', fn (Builder $project) => $project->where('workspace_id', $context->workspace->id))
                ->with('project.workspace')
                ->first();

            return $task !== null && Gate::forUser($context->user)->allows('view', $task);
        }

        if ($attachment->message_id === null) {
            /*
             * An unattached upload belongs to whoever is holding the composer
             * it was dropped into, and to nobody else yet. Bite is not that
             * person, and neither is the asker unless they uploaded it — which
             * is not a case worth serving, because a file nobody has sent is
             * not a file anybody can ask about.
             */
            return false;
        }

        /*
         * The constraint is written in `messages` columns, so it goes inside
         * the relation rather than onto the attachment query — see
         * {@see MessageVisibility::apply()}, which is generic over the builder
         * for exactly this pair of callers.
         */
        return MessageAttachment::query()
            ->whereKey($attachment->id)
            ->whereHas('message', fn (Builder $message) => $this->visibility->apply(
                $message,
                $context->workspace,
                $context->user,
            ))
            ->exists();
    }
}
