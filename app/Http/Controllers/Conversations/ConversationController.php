<?php

namespace App\Http\Controllers\Conversations;

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
use App\Models\Workspace;
use App\Services\MessageHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Direct and group conversations.
 *
 * Every action declares {workspace} first, matching the route's parameter order.
 * Laravel passes route parameters positionally, so omitting it on a nested route
 * would shift every subsequent argument by one.
 *
 * The workspace has already been resolved and its membership authorized by the
 * ResolveWorkspace middleware, and `scopeBindings()` ties {conversation} to that
 * workspace's own conversations — so an id belonging to another tenant is a 404
 * before any policy runs.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly MessageHistory $history,
        private readonly TogglePin $pins,
    ) {}

    /**
     * Opening "DMs" opens a conversation, not a menu.
     *
     * Redirects into the top of the DM list — the most recently active thread,
     * which is the row the reader would have clicked anyway. Landing on a list
     * beside a list would make them choose twice to get where they were going.
     *
     * Falls through to the roster page only when there is nothing to open yet.
     * The alternative — starting a conversation with whoever sorts first — would
     * mean a GET that creates a thread with somebody nobody chose, and a crawler
     * or a prefetch could do it.
     */
    public function index(Workspace $workspace, Request $request): Response|RedirectResponse
    {
        $conversation = $workspace->conversations()
            ->visibleTo($request->user())
            ->messaging()
            // The sidebar's own ordering — see WorkspaceNavigator — so "the top
            // of the list" means the same thing here as it looks like there.
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('id')
            ->first();

        if ($conversation !== null) {
            return to_route('conversations.show', [
                'workspace' => $workspace->slug,
                'conversation' => $conversation,
            ]);
        }

        /*
         * Deliberately propless. Every row it renders — the people, their
         * conversations, the unread counts — is already on the shared `snack`
         * prop that dresses every workspace page, because the sidebar renders
         * the same list.
         */
        return Inertia::render('conversations/index');
    }

    /**
     * Open a conversation with a set of people, creating it only if it is new.
     *
     * Redirects rather than answering with the conversation, because the client
     * always wants to *be* in it afterwards — and because messaging someone you
     * already have a thread with must land in that thread, not a copy of it.
     */
    public function store(
        Workspace $workspace,
        StoreConversationRequest $request,
        FindOrCreateConversation $findOrCreate,
    ): RedirectResponse {
        $conversation = $findOrCreate->handle(
            workspace: $workspace,
            creator: $request->user(),
            others: $request->participants(),
            name: $request->conversationName(),
        );

        return to_route('conversations.show', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation,
        ]);
    }

    public function show(Workspace $workspace, Conversation $conversation, Request $request): Response|RedirectResponse
    {
        $this->authorize('view', $conversation);

        /*
         * A Bite chat lives on the Bite page. Notifications, search results and
         * saved items all link here by conversation, so this is the one place
         * that sends every one of them on to the right page.
         */
        if ($conversation->type->isBite()) {
            return to_route('bite.chats.show', [
                'workspace' => $workspace->slug,
                'conversation' => $conversation,
            ]);
        }

        $user = $request->user();

        /*
         * `activeUsers` is the roster the header and participant list render.
         * `participants` is loaded constrained to the viewer, which is the only
         * row the resource and the message policies ever ask about — and loading
         * it here, before the history is built, is what keeps a page of messages
         * from re-querying it per message.
         */
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

        return Inertia::render('conversations/show', [
            'conversation' => (new ConversationResource($conversation))->resolve($request),
            'canvases' => CanvasResource::collection($canvases)->resolve($request),
            'canCreateCanvas' => $user->can('create', [Canvas::class, $workspace])
                && $user->can('post', $conversation),
            'canPost' => $user->can('post', $conversation),
            'canManage' => $user->can('rename', $conversation),
            'canAddPeople' => $user->can('addParticipants', $conversation),
            'canLeave' => $user->can('leave', $conversation),
            'canPin' => $user->can('pinMessages', $conversation),

            // The same pair the channel page carries — see there.
            'canHuddle' => $user->can('create', [Huddle::class, $conversation]),
            'huddle' => LiveHuddle::for($conversation, $request),

            // Ships with the page for the same reason it does in a channel.
            'pinned' => $this->pins->pinnedIn($conversation)
                ->map(fn (Message $message): array => (new MessageResource($message))->resolve($request))
                ->all(),

            /*
             * The newest page only. Older pages are fetched by the timeline as
             * the reader scrolls, so opening a conversation with years of
             * history costs the same as opening an empty one.
             */
            'history' => $this->history->page($conversation),
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
    ): RedirectResponse {
        $rename->handle($conversation, $request->user(), $request->conversationName());

        return back();
    }
}
