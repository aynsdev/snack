<?php

namespace App\Http\Controllers\Api\V1\Files;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\ContainerSummary;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FileCanvasSidebarContent;
use App\Services\MessageVisibility;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything shared in this workspace that the viewer is allowed to see.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Files\FileBrowserController},
 * whose query lives in private methods and is therefore repeated here — the
 * visibility rule itself is not: every row still narrows through
 * {@see MessageVisibility}, the same rule search uses.
 *
 * Cursor-paginated on the attachment id: pass the previous page's
 * `next_cursor` as `before`. Filters: `kind`, `channel` (id), `q` (filename).
 */
class FileBrowserController extends Controller
{
    public function __construct(
        private readonly MessageVisibility $visibility,
        private readonly WorkspaceContext $context,
        private readonly FileCanvasSidebarContent $sidebarContent,
    ) {}

    public function index(Workspace $workspace, Request $request): JsonResponse
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
            ->limit($perPage + 1)
            ->get();

        $hasMore = $files->count() > $perPage;
        $page = $files->take($perPage);

        return response()->json([
            'data' => [
                'files' => $page->map(fn (MessageAttachment $attachment): array => $this->row($attachment, $request))
                    ->values()
                    ->all(),
                'filters' => [
                    'kind' => $kind === '' ? null : $kind,
                    'channel' => $channelId,
                    'q' => $term === '' ? null : $term,
                ],
                'channels' => $this->channelOptions($workspace, $user),
                'next_cursor' => $hasMore ? $page->last()?->id : null,
                'file_canvas_sidebar' => $this->sidebarContent->for($workspace, $user),
            ],
        ]);
    }

    /**
     * @return Builder<MessageAttachment>
     */
    private function query(Workspace $workspace, User $user, string $kind, ?int $channelId, string $term): Builder
    {
        return MessageAttachment::query()
            ->where('workspace_id', $workspace->id)
            ->attached()
            ->whereHas('message', fn (Builder $message) => $this->visibility->apply($message, $workspace, $user))
            ->when($kind !== '', fn (Builder $query) => $query->ofKind($kind))
            // In addition to the visibility constraint, never instead of it.
            ->when($channelId !== null, fn (Builder $query) => $query
                ->whereHas('message', fn (Builder $message) => $message->where('channel_id', $channelId)))
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
            'container' => $attachment->message instanceof Message
                ? ContainerSummary::for($attachment->message)
                : null,
            'message_id' => $attachment->message_id,
        ];
    }

    /**
     * The channel filter's options: only channels the viewer can see.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    private function channelOptions(Workspace $workspace, User $user): array
    {
        return Channel::query()
            ->where('workspace_id', $workspace->id)
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
