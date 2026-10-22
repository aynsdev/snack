<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Actions\Workspaces\CreateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Services\WorkspaceNavigator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The workspaces a person belongs to, and the shell of the one they open.
 */
class WorkspaceController extends Controller
{
    public function __construct(private readonly WorkspaceNavigator $navigator) {}

    /**
     * The workspace switcher — the web app's rail.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->navigator->rail($request->user())]);
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspace $createWorkspace): JsonResponse
    {
        $workspace = $createWorkspace->handle($request->user(), $request->workspaceAttributes());

        return (new WorkspaceResource($workspace))->response()->setStatusCode(201);
    }

    /**
     * Everything the web app shares with every page inside a workspace: the
     * workspace itself, its sidebar, and the unread counts that badge it.
     * The app calls this on opening a workspace and after a reconnect.
     */
    public function show(Workspace $workspace, Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->navigator->payload($workspace, $request->user()),
                'landing_channel' => $this->navigator->landingChannel($workspace, $request->user())?->slug,
            ],
        ]);
    }
}
