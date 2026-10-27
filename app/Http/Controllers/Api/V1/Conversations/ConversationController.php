<?php

namespace App\Http\Controllers\Api\V1\Conversations;

use App\Actions\Conversations\FindOrCreateConversation;
use App\Actions\Conversations\RenameConversation;
use App\Actions\Messages\TogglePin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\StoreConversationRequest;
use App\Http\Requests\Conversations\UpdateConversationRequest;
use App\Http\Resources\CanvasResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\LiveHuddle;
use App\Http\Resources\MessageResource;
use App\Models\Canvas;
use App\Models\Conversation;
use App\Models\Huddle;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\MessageHistory;
use App\Services\WorkspaceNavigator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Direct and group conversations, for the mobile app.
 *
 * The web app's DM home redirects into the newest thread and leaves the list to
 * the sidebar; an app has no shared sidebar prop, so here the list is its own
 * endpoint. Opening one returns the same props the web page renders.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly MessageHistory $history,
        private readonly TogglePin $pins,
        private readonly WorkspaceNavigator $navigator,
    ) {}

    /**
     * The DM list: the viewer's recent conversations, and the roster of people
     * they can message — each carrying the direct thread they already share.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'conversations' => $this->navigator->sidebarConversations($workspace, $request->user()),
                'people' => $this->navigator->sidebarPeople($workspace, $request->user()),
            ],
        ]);
    }

    /**
     * Open a conversation with a set of people, creating it only if it is new.
     *
     * 201 when a conversation was created, 200 when the viewer already had one
     * with exactly these people — the client goes to it either way.
     */
    public function store(
        Workspace $workspace,
        StoreConversationRequest $request,
        FindOrCreateConversation $findOrCreate,
    ): JsonResponse {
        $conversation = $findOrCreate->handle(
            workspace: $workspace,
            creator: $request->user(),
            others: $request->participants(),
            name: $request->conversationName(),
        );

        return $this->resource($conversation, $request->user())
            ->response()
            ->setStatusCode($conversation->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * A Bite chat is sent on to its own endpoint, as the web page sends it to
     * the Bite page.
     */
    public function show(Workspace $workspace, Conversation $conversation, Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $conversation);

        if ($conversation->type->isBite()) {
            return to_route('api.v1.bite.chats.show', [
                'workspace' => $workspace->slug,
                'conversation' => $conversation,
            ]);
        }

        $user = $request->user();

        // Loaded before the history is built, so a page of messages does not
        // re-query the viewer's participant row per message.
        $conversation->load([
            'activeUsers',
            'participants' => fn ($query) => $query->where('user_id', $user->id),
        ]);

        $canvases = $conversation->canvases()
            ->with(['creator', 'editor'])
            ->get()
            ->each(fn ($canvas) => $canvas
                ->setRelation('workspace', $workspace)
                ->setRelation('conversation', $conversation));

        return response()->json([
            'data' => [
                'conversation' => (new ConversationResource($conversation))->resolve($request),
                'canvases' => CanvasResource::collection($canvases)->resolve($request),
                'abilities' => [
                    'create_canvas' => $user->can('create', [Canvas::class, $workspace])
                        && $user->can('post', $conversation),
                    'post' => $user->can('post', $conversation),
                    'manage' => $user->can('rename', $conversation),
                    'add_people' => $user->can('addParticipants', $conversation),
                    'leave' => $user->can('leave', $conversation),
                    'pin' => $user->can('pinMessages', $conversation),
                    'huddle' => $user->can('create', [Huddle::class, $conversation]),
                ],
                'huddle' => LiveHuddle::for($conversation, $request),
                'pinned' => $this->pins->pinnedIn($conversation)
                    ->map(fn (Message $message): array => (new MessageResource($message))->resolve($request))
                    ->all(),

                // The newest page only; older pages come from the messages
                // endpoint with `before`. `history=0` skips it, as for channels.
                'history' => $request->boolean('history', true) ? $this->history->page($conversation) : null,
            ],
        ]);
    }

    /**
     * Rename a group conversation, or clear its name.
     */
    public function update(
        Workspace $workspace,
        Conversation $conversation,
        UpdateConversationRequest $request,
        RenameConversation $rename,
    ): ConversationResource {
        $rename->handle($conversation, $request->user(), $request->conversationName());

        return $this->resource($conversation->refresh(), $request->user());
    }

    /**
     * The conversation as the viewer sees it, with the relations the resource
     * reads loaded up front.
     */
    private function resource(Conversation $conversation, User $user): ConversationResource
    {
        return new ConversationResource($conversation->load([
            'activeUsers',
            'participants' => fn ($query) => $query->where('user_id', $user->id),
        ]));
    }
}
