<?php

namespace App\Http\Requests\Concerns;

use App\Models\MessageAttachment;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Resolves the `attachment_ids` a send names into real, claimable uploads.
 *
 * # The rule this exists to enforce
 *
 * Like `parent_message_id`, this is a field that names other rows, and therefore
 * a field that could reach outside what the route already authorized. Three
 * separate things have to be true of every id, and none of them can be delegated
 * to an `exists` rule — which would happily confirm a file in someone else's
 * workspace:
 *
 *  - it belongs to *this* workspace,
 *  - it was uploaded by *this* user, so a member cannot attach a file they only
 *    know the id of to a message of their own, and
 *  - it is not already on a message, so an existing attachment cannot be
 *    re-pointed at a channel its original message was never in.
 *
 * All three are predicates on the query rather than checks after the fact, so a
 * failing id simply does not come back.
 *
 * # Why a partial match is a 404
 *
 * If any named id fails to resolve, the whole send is rejected rather than
 * quietly sent with fewer files. Silently dropping one would mean a person
 * pressing send on a message with three screenshots watching two of them
 * arrive — and a 404 rather than a 403 for the same reason a thread parent gets
 * one: "that file exists, just not for you" is itself the leak.
 */
trait ResolvesAttachments
{
    /**
     * @return Collection<int, MessageAttachment>
     */
    protected function resolveAttachments(Workspace $workspace): Collection
    {
        /** @var array<array-key, mixed> $input */
        $input = $this->input('attachment_ids', []);

        $ids = collect($input)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        $attachments = MessageAttachment::query()
            ->whereIn('id', $ids)
            ->where('workspace_id', $workspace->id)
            ->where('uploaded_by', $this->user()->id)
            ->orphaned()
            ->get();

        abort_if($attachments->count() !== $ids->count(), 404);

        return $attachments;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function attachmentRules(): array
    {
        return [
            'attachment_ids' => [
                'sometimes',
                'array',
                // Bounds the claim query and the resulting message. The composer
                // stops accepting files at the same number; this is the authority.
                'max:'.config('snack.uploads.per_message', 10),
            ],
            'attachment_ids.*' => ['integer', 'min:1'],
        ];
    }
}
