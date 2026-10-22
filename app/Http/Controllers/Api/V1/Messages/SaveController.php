<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContainerSummary;
use App\Http\Resources\MessageResource;
use App\Models\SavedMessage;
use App\Models\Workspace;
use App\Services\MessageContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "Saved" view: the viewer's private bookmarks in this workspace.
 *
 * The same query as {@see \App\Http\Controllers\Messages\SaveController::index()};
 * saving and unsaving route to that controller directly.
 */
class SaveController extends Controller
{
    public function __construct(private readonly MessageContainers $containers) {}

    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $saved = SavedMessage::query()
            ->where('user_id', $request->user()->id)
            // Through the relation, so other tenants' and deleted messages drop out.
            ->whereHas('message', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->with([
                'message' => fn ($query) => $query->withTimelineRelations($request->user())->with([
                    'channel',
                    'conversation',
                ]),
            ])
            ->orderByDesc('id')
            ->limit((int) config('snack.saved.list_limit', 50))
            ->get();

        $this->containers->prime($saved->pluck('message'), $request->user());

        return response()->json([
            'data' => $saved
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
}
