<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\CreateChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\StoreChannelRequest;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\Workspace;
use App\Services\WorkspaceNavigator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every action declares {workspace} first, matching the route's parameter
 * order. Laravel passes route parameters to controller methods positionally, so
 * omitting it on a nested route would shift each subsequent argument by one.
 *
 * The workspace has already been resolved and its membership authorized by the
 * ResolveWorkspace middleware.
 */
class ChannelController extends Controller
{
    public function __construct(private readonly WorkspaceNavigator $navigator) {}

    /**
     * The channel browser.
     */
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [Channel::class, $workspace]);

        return Inertia::render('channels/browse', [
            'joinable' => $this->navigator->joinableChannels($workspace, $request->user()),
            'joined' => $this->navigator->sidebarChannels($workspace, $request->user()),
        ]);
    }

    public function store(
        Workspace $workspace,
        StoreChannelRequest $request,
        CreateChannel $createChannel,
    ): RedirectResponse {
        $channel = $createChannel->handle(
            workspace: $workspace,
            creator: $request->user(),
            attributes: [
                'name' => $request->string('name')->toString(),
                'description' => $request->input('description'),
                'topic' => $request->input('topic'),
                'type' => $request->channelType(),
            ],
        );

        return to_route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ])->with('success', "#{$channel->slug} created.");
    }

    /**
     * The channel view.
     *
     * Phase 1 renders the header, details panel, and an empty timeline; the
     * message history and composer are wired up in Phase 2.
     */
    public function show(Workspace $workspace, Channel $channel, Request $request): Response
    {
        $this->authorize('view', $channel);

        $user = $request->user();

        $channel->loadCount('members');

        // Constrained to the viewer so ChannelResource can read their unread
        // pointer and mute flag without a second query.
        $channel->load(['members' => fn ($query) => $query->where('user_id', $user->id)]);

        return Inertia::render('channels/show', [
            'channel' => (new ChannelResource($channel))->resolve($request),
            'canPost' => $user->can('post', $channel),
            'canManage' => $user->can('update', $channel),

            // Deferred so the first paint is the conversation, not the roster.
            'members' => Inertia::defer(fn () => UserResource::collection(
                $channel->users()->orderBy('name')->limit(100)->get(),
            )->resolve($request)),
        ]);
    }
}
