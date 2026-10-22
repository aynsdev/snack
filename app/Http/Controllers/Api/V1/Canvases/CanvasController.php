<?php

namespace App\Http\Controllers\Api\V1\Canvases;

use App\Actions\Attachments\DeleteUpload;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCanvasRequest;
use App\Http\Requests\UpdateCanvasRequest;
use App\Http\Resources\CanvasResource;
use App\Models\Canvas;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\CanvasDocument;
use App\Services\CanvasVisibility;
use App\Services\FileCanvasSidebarContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Canvases for the mobile app: durable notes, briefs and reference documents.
 *
 * The JSON mirror of {@see \App\Http\Controllers\CanvasController}.
 *
 * # The document
 *
 * `content` is a Tiptap (ProseMirror) JSON tree, saved whole — there is no
 * collaborative or incremental protocol. A PATCH sends `title` and the entire
 * `content`, and {@see CanvasDocument} rejects any node or mark the web editor
 * does not register, so a mobile editor must stay inside that same schema.
 * `body` is the server's plain-text flattening of it, for search and excerpts.
 */
class CanvasController extends Controller
{
    public function __construct(
        private readonly CanvasVisibility $visibility,
        private readonly FileCanvasSidebarContent $sidebarContent,
    ) {}

    /**
     * Query: `q` (title/body search), `scope` (all|created|shared), `sort`
     * (updated|created|title), `page` — simple pagination, 30 a page.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [Canvas::class, $workspace]);

        $user = $request->user();
        $user->loadMissing('workspaceMemberships');
        $term = $request->string('q')->squish()->limit(120, '')->toString();
        $scope = $request->string('scope')->toString();
        $scope = in_array($scope, ['created', 'shared'], true) ? $scope : 'all';
        $sort = $request->string('sort')->toString();
        $sort = in_array($sort, ['updated', 'created', 'title'], true) ? $sort : 'updated';

        $canvases = $this->visibility
            ->query($workspace, $user)
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('title', 'ilike', "%{$term}%")
                        ->orWhere('body', 'ilike', "%{$term}%");
                });
            })
            ->when($scope === 'created', fn (Builder $query) => $query->where('created_by', $user->id))
            ->when($scope === 'shared', fn (Builder $query) => $query->where('created_by', '!=', $user->id))
            ->with(['creator', 'editor', 'channel', 'conversation.activeUsers'])
            ->withExists([
                'stars as is_starred' => fn (Builder $query) => $query->where('user_id', $user->id),
            ])
            ->when($sort === 'updated', fn (Builder $query) => $query->orderByDesc('updated_at'))
            ->when($sort === 'created', fn (Builder $query) => $query->orderByDesc('created_at'))
            ->when($sort === 'title', fn (Builder $query) => $query->orderBy('title'))
            ->simplePaginate(30)
            ->withQueryString();

        $canvases->getCollection()->each(
            fn (Canvas $canvas) => $canvas->setRelation('workspace', $workspace),
        );

        return response()->json([
            'data' => [
                'canvases' => CanvasResource::collection($canvases->getCollection())->resolve($request),
                'filters' => [
                    'q' => $term === '' ? null : $term,
                    'scope' => $scope,
                    'sort' => $sort,
                ],
                'pagination' => [
                    'current_page' => $canvases->currentPage(),
                    'has_more' => $canvases->hasMorePages(),
                    'next' => $canvases->nextPageUrl(),
                    'previous' => $canvases->previousPageUrl(),
                ],
                'abilities' => [
                    'create' => $user->can('create', [Canvas::class, $workspace]),
                ],
                'file_canvas_sidebar' => $this->sidebarContent->for($workspace, $user),
            ],
        ]);
    }

    /**
     * A workspace-level canvas, blank or from a `template`.
     */
    public function store(Workspace $workspace, StoreCanvasRequest $request, CanvasDocument $documents): JsonResponse
    {
        $validated = $request->validated();
        $preset = isset($validated['template'])
            ? $documents->template($validated['template'])
            : ['title' => 'Untitled', 'content' => $documents->empty()];

        $canvas = $this->createCanvas(
            workspace: $workspace,
            creatorId: $request->user()->id,
            content: $preset['content'],
            title: $preset['title'],
            body: $documents->text($preset['content']),
        );

        return $this->resource($workspace, $canvas, $request)->response()->setStatusCode(201);
    }

    public function storeForChannel(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        CanvasDocument $documents,
    ): JsonResponse {
        $this->authorize('create', [Canvas::class, $workspace]);
        $this->authorize('post', $channel);

        $canvas = $this->createCanvas(
            workspace: $workspace,
            creatorId: $request->user()->id,
            content: $documents->empty(),
            channel: $channel,
        );

        return $this->resource($workspace, $canvas, $request)->response()->setStatusCode(201);
    }

    public function storeForConversation(
        Workspace $workspace,
        Conversation $conversation,
        Request $request,
        CanvasDocument $documents,
    ): JsonResponse {
        $this->authorize('create', [Canvas::class, $workspace]);
        $this->authorize('post', $conversation);

        $canvas = $this->createCanvas(
            workspace: $workspace,
            creatorId: $request->user()->id,
            content: $documents->empty(),
            conversation: $conversation,
        );

        return $this->resource($workspace, $canvas, $request)->response()->setStatusCode(201);
    }

    public function show(Workspace $workspace, Canvas $canvas, Request $request): CanvasResource
    {
        $this->authorize('view', $canvas);

        return $this->resource($workspace, $canvas, $request);
    }

    /**
     * Saves the whole document: `title` and the full Tiptap `content` tree.
     */
    public function update(
        Workspace $workspace,
        Canvas $canvas,
        UpdateCanvasRequest $request,
        CanvasDocument $documents,
    ): CanvasResource {
        /** @var array<string, mixed> $content */
        $content = $request->validated('content');

        $canvas->update([
            'title' => $request->string('title')->squish()->toString(),
            'body' => $documents->text($content),
            'content' => $content,
            'updated_by' => $request->user()->id,
        ]);

        return $this->resource($workspace, $canvas, $request);
    }

    public function destroy(Workspace $workspace, Canvas $canvas, DeleteUpload $deletions): Response
    {
        $this->authorize('delete', $canvas);

        if ($canvas->cover !== null) {
            $deletions->handle($canvas->cover);
        }

        $canvas->delete();

        return response()->noContent();
    }

    /**
     * One canvas with everything the canvas view shows: the document, its
     * cover, its container and whether the viewer starred it.
     */
    private function resource(Workspace $workspace, Canvas $canvas, Request $request): CanvasResource
    {
        $request->user()->loadMissing('workspaceMemberships');

        $canvas->load(['creator', 'editor', 'cover', 'channel', 'conversation.activeUsers'])
            ->loadExists([
                'stars as is_starred' => fn (Builder $query) => $query->where('user_id', $request->user()->id),
            ])
            ->setRelation('workspace', $workspace);

        return new CanvasResource($canvas);
    }

    /** @param array<string, mixed> $content */
    private function createCanvas(
        Workspace $workspace,
        int $creatorId,
        array $content,
        string $title = 'Untitled',
        string $body = '',
        ?Channel $channel = null,
        ?Conversation $conversation = null,
    ): Canvas {
        return Canvas::query()->create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel?->id,
            'conversation_id' => $conversation?->id,
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
            'title' => $title,
            'body' => $body,
            'content' => $content,
        ]);
    }
}
