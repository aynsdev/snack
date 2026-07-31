<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\ContainerSummary;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MessageVisibility;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Everything shared in this workspace that the viewer is allowed to see.
 *
 * # Why this is not just "the attachments table"
 *
 * A file's audience is the audience of the message carrying it, and this view
 * spans the whole workspace rather than one container — so every row is filtered
 * through {@see MessageVisibility}, the same rule search uses. Without it, a file
 * browser would be a complete index of every private channel's attachments,
 * which is the most efficient possible way to leak them.
 *
 * Unattached uploads never appear: they belong to a composer somebody has open,
 * not to the workspace.
 *
 * # Pagination
 *
 * Cursor-based on the attachment's own id, for the same reason the timeline is:
 * files keep arriving while the browser is open, and an OFFSET would duplicate
 * or skip rows every time one did. The prop is merged rather than replaced, so
 * "load more" appends instead of starting over.
 */
class FileBrowserController extends Controller
{
    public function __construct(
        private readonly MessageVisibility $visibility,
        private readonly WorkspaceContext $context,
    ) {}

    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $user = $request->user();
        $perPage = (int) config('snack.uploads.browser_page_size', 60);

        $kind = $request->string('kind')->toString();
        $channelId = $request->integer('channel') ?: null;
        $term = $request->string('q')->squish()->limit(120, '')->toString();
        $cursor = $request->integer('before') ?: null;

        $files = $this->query($workspace, $user, $kind, $channelId, $term)
            ->when($cursor !== null, fn (Builder $query) => $query->where('id', '<', $cursor))
            ->orderByDesc('id')
            // One extra row answers "is there more?" without a second COUNT.
            ->limit($perPage + 1)
            ->get();

        $hasMore = $files->count() > $perPage;
        $page = $files->take($perPage);

        return Inertia::render('files/index', [
            'files' => Inertia::merge(
                $page->map(fn (MessageAttachment $attachment): array => $this->row($attachment, $request))
                    ->values()
                    ->all(),
            ),

            'filters' => [
                'kind' => $kind === '' ? null : $kind,
                'channel' => $channelId,
                'q' => $term === '' ? null : $term,
            ],

            // The channel filter's options: only ones the viewer can see, so the
            // dropdown cannot become a directory of private channel names.
            'channels' => $this->channelOptions($workspace, $user),

            'next_cursor' => $hasMore ? $page->last()?->id : null,
        ]);
    }

    /**
     * @return Builder<MessageAttachment>
     */
    private function query(
        Workspace $workspace,
        User $user,
        string $kind,
        ?int $channelId,
        string $term,
    ): Builder {
        return MessageAttachment::query()
            ->where('workspace_id', $workspace->id)
            ->attached()
            ->whereHas('message', fn (Builder $message) => $this->visibility->apply($message, $workspace, $user))
            ->when($kind !== '', fn (Builder $query) => $query->ofKind($kind))
            /*
             * The channel filter is applied through the message and *in addition
             * to* the visibility constraint above, never instead of it: an id
             * naming a channel the viewer cannot see intersects with nothing, so
             * it narrows the results rather than widening them.
             */
            ->when($channelId !== null, fn (Builder $query) => $query
                ->whereHas('message', fn (Builder $message) => $message->where('channel_id', $channelId)))
            /*
             * Filename matching. A contains-pattern rather than the trigram
             * similarity operator, so a two-letter prefix still matches what the
             * user obviously means — Postgres can still use the GIN trigram index
             * laid down in Phase 1 for it.
             */
            ->when($term !== '', fn (Builder $query) => $query
                ->where('original_name', 'ilike', '%'.addcslashes($term, '%_\\').'%'))
            ->with([
                'uploader:id,name,username,avatar_path,title,presence_status',
                'message' => fn ($message) => $message->with([
                    'channel:id,name,slug,type,workspace_id',
                    'conversation',
                ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MessageAttachment $attachment, Request $request): array
    {
        return [
            ...AttachmentResource::payload($attachment),

            'uploader' => $attachment->uploader === null
                ? null
                : (new UserResource($attachment->uploader))->resolve($request),

            // Where it was shared, so a row can say "in #design" and link there.
            'container' => $attachment->message instanceof Message
                ? ContainerSummary::for($attachment->message)
                : null,

            'message_id' => $attachment->message_id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function channelOptions(Workspace $workspace, User $user): array
    {
        return Channel::query()
            ->where('workspace_id', $workspace->id)
            // The middleware guarantees an active membership, so the role is
            // present by the time any of this runs.
            ->visibleTo($user, $this->context->role())
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
            ])
            ->all();
    }
}
