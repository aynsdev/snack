<?php

namespace App\Http\Controllers;

use App\Actions\Workspaces\CreateWorkspace;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Models\Workspace;
use App\Services\WorkspaceNavigator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function __construct(private readonly WorkspaceNavigator $navigator) {}

    /**
     * The workspace picker, shown when someone has no workspace selected.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $workspaces = $this->navigator->rail($user);

        // With exactly one workspace there is nothing to pick, so skip straight
        // to it. This is the common case for an internal deployment.
        if (count($workspaces) === 1) {
            return to_route('workspaces.show', ['workspace' => $workspaces[0]['slug']]);
        }

        return Inertia::render('workspace/index', [
            'workspaces' => $workspaces,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('workspace/create');
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $workspace = $createWorkspace->handle($request->user(), $request->workspaceAttributes());

        return to_route('workspaces.show', ['workspace' => $workspace->slug])
            ->with('success', "{$workspace->name} is ready. Small chats, big work.");
    }

    /**
     * Entering a workspace drops the user into a channel rather than a landing
     * page, which is what makes Snack feel like a chat app instead of a
     * dashboard.
     */
    public function show(Workspace $workspace, Request $request): RedirectResponse|Response
    {
        $channel = $this->navigator->landingChannel($workspace, $request->user());

        if ($channel !== null) {
            return to_route('channels.show', [
                'workspace' => $workspace->slug,
                'channel' => $channel->slug,
            ]);
        }

        // A guest who has not been added to anything yet, or a workspace whose
        // channels have all been archived.
        return Inertia::render('workspace/empty', [
            'canCreateChannels' => $request->user()->roleIn($workspace)?->canCreateChannels() ?? false,
        ]);
    }
}
