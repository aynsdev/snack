<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\UpdateChannel;
use App\Actions\Messages\TogglePin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\StoreChannelRequest;
use App\Http\Requests\Channels\UpdateChannelRequest;
use App\Http\Resources\CanvasResource;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\LiveHuddle;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Models\Canvas;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Huddle;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\MessageHistory;
use App\Services\WorkspaceNavigator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Channels for the mobile app: the browser, creating and editing one, and the
 * channel view.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Channels\ChannelController}.
 * The web page's deferred `members` prop is its own endpoint here, so the app
 * can paint the conversation first and fetch the roster when it is opened.
 */
class ChannelController extends Controller
{
    public function __construct(
        private readonly WorkspaceNavigator $navigator,
        private readonly MessageHistory $history,
        private readonly TogglePin $pins,
    ) {}

    /**
     * The channel browser: what the viewer could join, and what they are in.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [Channel::class, $workspace]);

        return response()->json([
            'data' => [
                'joinable' => $this->navigator->joinableChannels($workspace, $request->user()),
                'joined' => $this->navigator->sidebarChannels($workspace, $request->user()),
            ],
        ]);
    }

    public function store(
        Workspace $workspace,
        StoreChannelRequest $request,
        CreateChannel $createChannel,
    ): JsonResponse {
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

        return $this->resource($channel, $request)->response()->setStatusCode(201);
    }

    /**
     * The channel view: everything the web page paints on its first frame.
     */
    public function show(Workspace $workspace, Channel $channel, Request $request): JsonResponse
    {
        $this->authorize('view', $channel);

        $user = $request->user();

        $canvases = $channel->canvases()
            ->with(['creator', 'editor'])
            ->get()
            ->each(fn ($canvas) => $canvas
                ->setRelation('workspace', $workspace)
                ->setRelation('channel', $channel));

        return response()->json([
            'data' => [
                'channel' => $this->resource($channel, $request)->resolve($request),
                'canvases' => CanvasResource::collection($canvases)->resolve($request),
                'abilities' => [
                    'can_post' => $user->can('post', $channel),
                    'can_manage' => $user->can('update', $channel),
                    'can_pin' => $user->can('pinMessages', $channel),
                    'can_huddle' => $user->can('create', [Huddle::class, $channel]),
                    'can_create_canvas' => $user->can('create', [Canvas::class, $workspace])
                        && $user->can('post', $channel),
                ],
                'huddle' => LiveHuddle::for($channel, $request),
                'pinned' => $this->pins->pinnedIn($channel)
                    ->map(fn (Message $message): array => (new MessageResource($message))->resolve($request))
                    ->all(),

                // The newest page; older ones come from `channels/{channel}/messages?before=`.
                // `history=0` skips it for clients that fetch that page in
                // parallel rather than waiting on this whole response for it.
                'history' => $request->boolean('history', true) ? $this->history->page($channel) : null,
            ],
        ]);
    }

    /**
     * Rename, re-describe, or re-type. A rename regenerates the slug, so the
     * app must re-address the channel by the `slug` that comes back.
     */
    public function update(
        Workspace $workspace,
        Channel $channel,
        UpdateChannelRequest $request,
        UpdateChannel $updateChannel,
    ): ChannelResource {
        $updated = $updateChannel->handle(
            channel: $channel,
            actor: $request->user(),
            attributes: $request->channelAttributes(),
        );

        return $this->resource($updated, $request);
    }

    /**
     * The roster — the web page's deferred `members` prop. Capped at 100,
     * alphabetical, like the panel it fills.
     */
    public function members(Workspace $workspace, Channel $channel, Request $request): JsonResponse
    {
        $this->authorize('view', $channel);

        $members = $channel->members()
            ->with('user')
            ->join('users', 'users.id', '=', 'channel_members.user_id')
            ->orderBy('users.name')
            ->select('channel_members.*')
            ->limit(100)
            ->get()
            ->map(fn (ChannelMember $membership): array => [
                'id' => $membership->id,
                'role' => $membership->role->value,
                'joined_at' => $membership->joined_at->toIso8601String(),
                'user' => (new UserResource($membership->user))->resolve($request),
            ])
            ->all();

        return response()->json(['data' => $members]);
    }

    /**
     * The channel as the viewer sees it — ChannelResource reads their unread
     * pointer, mute flag and section off the constrained membership relation.
     */
    private function resource(Channel $channel, Request $request): ChannelResource
    {
        $channel->loadCount('members');
        $channel->load(['members' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return new ChannelResource($channel);
    }
}
