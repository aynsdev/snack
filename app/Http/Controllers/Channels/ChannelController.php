<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\CreateChannel;
use App\Actions\Messages\TogglePin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\StoreChannelRequest;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\MessageHistory;
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
    public function __construct(
        private readonly WorkspaceNavigator $navigator,
        private readonly MessageHistory $history,
        private readonly TogglePin $pins,
    ) {}

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
            'canPin' => $user->can('pinMessages', $channel),

            /*
             * Pinned messages are reference material, so they ship with the page
             * rather than being fetched after it paints — the bar appearing a
             * beat late is exactly the flicker at the top of the conversation
             * that makes a channel feel unsettled.
             */
            'pinned' => $this->pins->pinnedIn($channel)
                ->map(fn (Message $message): array => (new MessageResource($message))->resolve($request))
                ->all(),

            /*
             * The newest page only. Older pages are fetched by the timeline as
             * the reader scrolls, so opening a channel with ten years of
             * history costs exactly the same as opening an empty one.
             *
             * Sent as a prop rather than fetched on mount so the channel paints
             * with its conversation already in place — one round trip, no
             * empty-then-populated flash.
             */
            'history' => $this->history->page($channel),

            // Deferred so the first paint is the conversation, not the roster.
            'members' => Inertia::defer(fn () => UserResource::collection(
                $channel->users()->orderBy('name')->limit(100)->get(),
            )->resolve($request)),
        ]);
    }
}
