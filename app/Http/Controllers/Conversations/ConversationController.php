<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\FindOrCreateConversation;
use App\Actions\Conversations\RenameConversation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\StoreConversationRequest;
use App\Http\Requests\Conversations\UpdateConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
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
    public function __construct(private readonly MessageHistory $history) {}

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

    public function show(Workspace $workspace, Conversation $conversation, Request $request): Response
    {
        $this->authorize('view', $conversation);

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

        return Inertia::render('conversations/show', [
            'conversation' => (new ConversationResource($conversation))->resolve($request),
            'canPost' => $user->can('post', $conversation),
            'canManage' => $user->can('rename', $conversation),
            'canAddPeople' => $user->can('addParticipants', $conversation),
            'canLeave' => $user->can('leave', $conversation),

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
