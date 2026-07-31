<?php

namespace App\Http\Controllers\Messages;

use App\Actions\Messages\ToggleSave;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContainerSummary;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\SavedMessage;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Private bookmarks.
 *
 * Authorized as `save`, which resolves to plain read access: anything you may
 * read, you may keep a pointer to. Nothing here is broadcast — telling the
 * channel what you are keeping track of is the opposite of what a private
 * bookmark is for.
 */
class SaveController extends Controller
{
    public function __construct(private readonly ToggleSave $saves) {}

    /**
     * The "Saved" view: everything this user has bookmarked in this workspace.
     */
    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $saved = SavedMessage::query()
            ->where('user_id', $request->user()->id)
            /*
             * Constrained to this workspace through the message, so switching
             * workspaces does not show bookmarks from another tenant — and
             * `whereHas` runs through the relation, which carries Message's
             * soft-delete scope, so a bookmark on a deleted message drops out
             * rather than rendering as a gap.
             */
            ->whereHas('message', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with([
                'message' => fn ($query) => $query->withTimelineRelations($request->user())->with([
                    'channel:id,name,slug,type,workspace_id',
                    'conversation',
                ]),
            ])
            ->orderByDesc('id')
            ->limit((int) config('snack.saved.list_limit', 50))
            ->get();

        return Inertia::render('saved/index', [
            'saved' => $saved
                ->map(fn (SavedMessage $bookmark): array => [
                    'id' => $bookmark->id,
                    'notes' => $bookmark->notes,
                    'saved_at' => $bookmark->created_at?->toIso8601String(),
                    'message' => (new MessageResource($bookmark->message))->resolve($request),
                    'container' => ContainerSummary::for($bookmark->message),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(
        Workspace $workspace,
        Message $message,
        Request $request,
    ): JsonResponse {
        $this->authorize('save', $message);

        $this->saves->save($message, $request->user());

        return response()->json(['message_id' => $message->id, 'is_saved' => true]);
    }

    public function destroy(
        Workspace $workspace,
        Message $message,
        Request $request,
    ): JsonResponse {
        $this->authorize('save', $message);

        $this->saves->unsave($message, $request->user());

        return response()->json(['message_id' => $message->id, 'is_saved' => false]);
    }
}
