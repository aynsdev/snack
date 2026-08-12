<?php

namespace App\Ai\Tools;

use App\Models\Asset;
use App\Models\MessageAttachment;
use App\Services\MessageVisibility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * "Where is the file about X?"
 *
 * Snack keeps files in two places that are really one place: the asset library,
 * which is a curated shelf, and message attachments, which is everything anyone
 * ever dropped into a conversation. Both are rows in `message_attachments` —
 * see that table's migration — so both are searched here and both come back
 * with the same kind of id, which is what {@see ReadFile} takes.
 *
 * # The two halves have different audiences
 *
 * A file shared in a message is as private as the message carrying it, so that
 * half is filtered through {@see MessageVisibility}, exactly as the file
 * browser is. The library has no private shelves — every member sees all of it
 * — except for guests, who are refused it entirely. Both rules are the ones
 * {@see App\Policies\AssetPolicy} and the file browser already apply; they are
 * restated as query constraints here because a tool cannot ask a policy about
 * rows it has not fetched yet.
 */
class FindFiles extends BiteTool implements Tool
{
    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Find files in this workspace by name — both the curated asset library
        (logos, contracts, brand assets, company documents) and files shared as
        message attachments.

        Returns a numeric file id for each match. Pass that id to `read_file` to
        actually read what is inside a file, and to `attach_file` to deliver a
        copy of it into the conversation.

        Results are already limited to files the person asking is allowed to see.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $term = $this->term($this->arg($request, 'query'), 120);
        $kind = $this->kind($this->arg($request, 'kind'));
        $limit = $this->limit($this->arg($request, 'limit'), 10, 25);

        $files = $this->library($term, $kind, $limit)
            ->concat($this->shared($term, $kind, $limit))
            /*
             * A library file is also a message attachment whenever somebody
             * shared it before it was shelved, so the two halves overlap. Keyed
             * by id, the library row wins because it was collected first — and
             * it is the more useful of the two descriptions, since it carries
             * the name people actually call the file.
             */
            ->unique(fn (array $row): int => $row['id'])
            ->take($limit);

        return $this->list(
            $files->map(fn (array $row): string => $row['line'])->all(),
            $term === ''
                ? 'There are no files here that this person can see.'
                : "No files match [{$term}].",
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Words from the file name, or from its description in the asset library. Leave empty to list recent files.'),
            'kind' => $schema->string()
                ->description('Narrow to one kind of file.')
                ->enum(['image', 'pdf', 'video', 'audio', 'document']),
            'limit' => $schema->integer()
                ->description('How many files to return. Defaults to 10, maximum 25.')
                ->min(1)
                ->max(25),
        ];
    }

    /**
     * The asset library half.
     *
     * @return Collection<int, array{id: int, line: string}>
     */
    private function library(string $term, ?string $kind, int $limit): Collection
    {
        /*
         * A guest is somebody brought in for one channel — a contractor, a
         * customer — and the library is where the unsigned contract and the
         * price list live. Refused outright rather than filtered, which is the
         * rule AssetPolicy states.
         */
        if ($this->context->isGuest()) {
            return new Collection;
        }

        /*
         * Queried from the attachment side rather than from `Asset`, even
         * though the library is the thing being searched. Two reasons: the kind
         * filter is a scope on the attachment, so this way it applies directly
         * instead of through a `whereHas` that loses the model's type; and a
         * library row whose file has been swept — bytes that are gone, nothing
         * to read — falls out of the join instead of needing to be filtered
         * back out afterwards.
         */
        return MessageAttachment::query()
            ->where('workspace_id', $this->context->workspace->id)
            ->whereHas('asset', fn (Builder $asset) => $asset
                ->where('workspace_id', $this->context->workspace->id)
                ->when($term !== '', fn (Builder $query) => $query->where(
                    fn (Builder $group) => $group
                        ->where('name', 'ilike', '%'.$term.'%')
                        ->orWhere('description', 'ilike', '%'.$term.'%'),
                )))
            ->when($kind !== null, fn (Builder $query) => $query->ofKind($kind))
            ->with(['asset.folder'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (MessageAttachment $file): array => [
                'id' => $file->id,
                'line' => $this->assetRow($file),
            ])
            ->values();
    }

    /**
     * The shared-in-a-conversation half.
     *
     * @return Collection<int, array{id: int, line: string}>
     */
    private function shared(string $term, ?string $kind, int $limit): Collection
    {
        $visibility = app(MessageVisibility::class);

        return MessageAttachment::query()
            ->where('workspace_id', $this->context->workspace->id)
            /*
             * Through the message, never around it. A file's audience is the
             * audience of the message carrying it — the rule the file browser
             * is built on — and `whereHas` is what makes an unattached upload,
             * which belongs to somebody's open composer rather than to the
             * workspace, invisible here too.
             */
            ->whereHas('message', fn (Builder $message) => $visibility->apply(
                $message,
                $this->context->workspace,
                $this->context->user,
            ))
            ->when($term !== '', fn (Builder $query) => $query->where('original_name', 'ilike', '%'.$term.'%'))
            ->when($kind !== null, fn (Builder $query) => $query->ofKind($kind))
            ->with(['message.channel', 'message.sender'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (MessageAttachment $attachment): array => [
                'id' => $attachment->id,
                'line' => $this->attachmentRow($attachment),
            ])
            ->values();
    }

    private function assetRow(MessageAttachment $file): string
    {
        $asset = $file->asset;

        if ($asset === null) {
            // Unreachable through the query above, which joins on the asset.
            // Kept as a fallback rather than an assertion because the shelf
            // entry could be deleted between the query and the render, and a
            // file with no name is still a file worth naming.
            return $this->attachmentRow($file);
        }

        // Tested on the column rather than the relation: `folder_id` is what is
        // actually nullable here, and a shelf at the root has no row to load.
        $folder = $asset->folder_id === null ? 'the top level' : $asset->folder->name;
        $note = $asset->description !== null && $asset->description !== ''
            ? "\n  {$asset->description}"
            : '';

        return "file #{$file->id} — {$asset->name} ({$file->mime_type}, {$file->humanSize()})"
            ."\n  in the asset library, under {$folder}; original filename {$file->original_name}"
            .$note;
    }

    private function attachmentRow(MessageAttachment $attachment): string
    {
        $message = $attachment->message;
        $where = $message?->channel !== null
            ? '#'.$message->channel->name
            : 'a direct message';
        $who = $message?->sender->name ?? 'someone';
        $when = $attachment->created_at?->toDayDateTimeString() ?? 'unknown time';

        return "file #{$attachment->id} — {$attachment->original_name} ({$attachment->mime_type}, {$attachment->humanSize()})"
            ."\n  shared by {$who} in {$where}, {$when}";
    }

    /**
     * The model may only ask for kinds the attachment scope understands.
     */
    private function kind(mixed $value): ?string
    {
        $kind = is_string($value) ? strtolower($value) : '';

        return in_array($kind, ['image', 'pdf', 'video', 'audio', 'document'], true)
            ? $kind
            : null;
    }
}
