<?php

namespace App\Http\Controllers;

use App\Actions\Attachments\DeleteUpload;
use App\Http\Requests\StoreCanvasRequest;
use App\Http\Requests\UpdateCanvasRequest;
use App\Http\Resources\CanvasResource;
use App\Models\Canvas;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\CanvasDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Workspace canvases: durable notes, briefs, and reference documents.
 *
 * Documents may live at workspace level or inside one channel/conversation.
 */
class CanvasController extends Controller
{
    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $this->authorize('viewAny', [Canvas::class, $workspace]);

        $user = $request->user();
        $user->loadMissing('workspaceMemberships');

        $term = $request->string('q')->squish()->limit(120, '')->toString();

        $canvases = Canvas::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('channel_id')
            ->whereNull('conversation_id')
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('title', 'ilike', "%{$term}%")
                        ->orWhere('body', 'ilike', "%{$term}%");
                });
            })
            ->with(['creator', 'editor'])
            ->orderByDesc('updated_at')
            ->simplePaginate(30)
            ->withQueryString();

        $canvases->getCollection()->each(
            fn (Canvas $canvas) => $canvas->setRelation('workspace', $workspace),
        );

        return Inertia::render('canvases/index', [
            'canvases' => CanvasResource::collection($canvases->getCollection())->resolve($request),
            'filters' => ['q' => $term === '' ? null : $term],
            'pagination' => [
                'next' => $canvases->nextPageUrl(),
                'previous' => $canvases->previousPageUrl(),
            ],
            'abilities' => [
                'create' => $user->can('create', [Canvas::class, $workspace]),
            ],
        ]);
    }

    public function store(
        Workspace $workspace,
        StoreCanvasRequest $request,
        CanvasDocument $documents,
    ): RedirectResponse {
        $canvas = $this->createCanvas(
            workspace: $workspace,
            creatorId: $request->user()->id,
            content: $documents->empty(),
        );

        return to_route('canvases.show', [$workspace, $canvas])
            ->with('success', 'Canvas created.');
    }

    public function storeForChannel(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        CanvasDocument $documents,
    ): RedirectResponse {
        $this->authorize('create', [Canvas::class, $workspace]);
        $this->authorize('post', $channel);

        $canvas = $this->createCanvas(
            workspace: $workspace,
            creatorId: $request->user()->id,
            content: $documents->empty(),
            channel: $channel,
        );

        return to_route('canvases.show', [$workspace, $canvas])
            ->with('success', 'Channel Canvas created.');
    }

    public function storeForConversation(
        Workspace $workspace,
        Conversation $conversation,
        Request $request,
        CanvasDocument $documents,
    ): RedirectResponse {
        $this->authorize('create', [Canvas::class, $workspace]);
        $this->authorize('post', $conversation);

        $canvas = $this->createCanvas(
            workspace: $workspace,
            creatorId: $request->user()->id,
            content: $documents->empty(),
            conversation: $conversation,
        );

        return to_route('canvases.show', [$workspace, $canvas])
            ->with('success', 'Conversation Canvas created.');
    }

    /** @param array<string, mixed> $content */
    private function createCanvas(
        Workspace $workspace,
        int $creatorId,
        array $content,
        ?Channel $channel = null,
        ?Conversation $conversation = null,
    ): Canvas {
        return Canvas::query()->create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel?->id,
            'conversation_id' => $conversation?->id,
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
            'title' => 'Untitled',
            'body' => '',
            'content' => $content,
        ]);
    }

    public function show(
        Workspace $workspace,
        Canvas $canvas,
        Request $request,
    ): InertiaResponse {
        $this->authorize('view', $canvas);

        $request->user()->loadMissing('workspaceMemberships');
        $canvas->load([
            'creator',
            'editor',
            'cover',
            'channel',
            'conversation.activeUsers',
        ])->setRelation('workspace', $workspace);

        return Inertia::render('canvases/show', [
            'canvas' => (new CanvasResource($canvas))->resolve($request),
        ]);
    }

    public function update(
        Workspace $workspace,
        Canvas $canvas,
        UpdateCanvasRequest $request,
        CanvasDocument $documents,
    ): RedirectResponse {
        $validated = $request->validated();
        /** @var array<string, mixed> $content */
        $content = $validated['content'];

        $canvas->update([
            'title' => $request->string('title')->squish()->toString(),
            'body' => $documents->text($content),
            'content' => $content,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Canvas saved.');
    }

    public function destroy(
        Workspace $workspace,
        Canvas $canvas,
        Request $request,
        DeleteUpload $deletions,
    ): RedirectResponse {
        $this->authorize('delete', $canvas);

        if ($canvas->cover !== null) {
            $deletions->handle($canvas->cover);
        }

        $canvas->delete();

        return to_route('canvases.index', $workspace)
            ->with('success', 'Canvas deleted.');
    }
}
