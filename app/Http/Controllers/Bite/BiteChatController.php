<?php

namespace App\Http\Controllers\Bite;

use App\Actions\Bite\StartBiteChat;
use App\Actions\Conversations\SendConversationMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bite\StoreBiteChatRequest;
use App\Http\Requests\Bite\UpdateBiteChatRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BiteChats;
use App\Services\BiteSummons;
use App\Services\MessageHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Bite page's Chats tab: a person's own conversations with Bite.
 *
 * Each chat is an ordinary conversation of type `bite` — see
 * {@see App\Enums\ConversationType::Bite} — so sending, history, broadcasting
 * and Bite's own answering all run through the code every DM uses. This
 * controller only lists them, opens one, and starts, renames, and deletes them.
 */
class BiteChatController extends Controller
{
    public function __construct(
        private readonly BiteSummons $summons,
        private readonly MessageHistory $history,
        private readonly BiteChats $chats,
    ) {}

    /**
     * The empty "new chat" state.
     */
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->ensureAvailable($workspace, $request->user());

        return Inertia::render('bite/show', [
            'biteChats' => $this->chats->chatsFor($workspace, $request->user(), $request->boolean('archived')),
            'biteArchived' => $request->boolean('archived'),
            'mediaModels' => fn () => $this->chats->mediaModels($workspace),
            'chat' => null,
            'history' => null,
            'canPost' => true,
        ]);
    }

    public function show(Workspace $workspace, Conversation $conversation, Request $request): Response
    {
        $user = $request->user();

        $this->authorizeChat($conversation, $user);

        $conversation->load([
            'activeUsers',
            'participants' => fn ($query) => $query->where('user_id', $user->id),
        ]);

        return Inertia::render('bite/show', [
            'biteChats' => fn () => $this->chats->chatsFor($workspace, $user, $conversation->archived_at !== null),
            'biteArchived' => $conversation->archived_at !== null,
            'chatMenu' => fn () => $this->chats->summary($conversation, $workspace),
            'mediaModels' => fn () => $this->chats->mediaModels($workspace),
            'chat' => fn () => (new ConversationResource($conversation))->resolve($request),
            'history' => fn () => $this->history->page($conversation),
            'mediaGenerations' => fn () => $this->chats->pendingGenerations($workspace, $conversation, $user),
            'canPost' => fn () => $user->can('post', $conversation) && $this->summons->availableTo($workspace, $user),
        ]);
    }

    /**
     * Start a chat with its first message, and go to it.
     *
     * One request rather than "create, then send": a chat with no message in it
     * is a row in the list titled "New chat" that nobody asked for.
     */
    public function store(
        Workspace $workspace,
        StoreBiteChatRequest $request,
        StartBiteChat $start,
        SendConversationMessage $send,
    ): RedirectResponse {
        $conversation = $start->handle($workspace, $request->user(), $request->messageBody());

        $send->handle(
            conversation: $conversation,
            sender: $request->user(),
            body: $request->messageBody(),
            attachments: $request->attachments(),
            biteModel: $request->biteModel(),
        );

        return to_route('bite.chats.show', [
            'workspace' => $workspace->slug,
            'conversation' => $conversation,
        ]);
    }

    /**
     * Retitle a chat.
     *
     * Not through RenameConversation, which announces the change with a system
     * message — right in a group, noise in a chat only its owner reads.
     */
    public function update(Workspace $workspace, Conversation $conversation, UpdateBiteChatRequest $request): RedirectResponse
    {
        $conversation->forceFill($request->chatAttributes())->save();

        return back();
    }

    /**
     * Delete a chat and everything in it.
     *
     * Outright rather than "leave": the only other participant is the bot, so
     * there is nobody left to keep the history for. Images and videos posted in
     * it go with it, and drop out of the Media tab, the way a deleted message
     * takes its files.
     */
    public function destroy(Workspace $workspace, Conversation $conversation, Request $request): RedirectResponse
    {
        $this->authorize('delete', $conversation);

        DB::transaction(fn () => $conversation->delete());

        return to_route('bite.index', ['workspace' => $workspace->slug]);
    }

    private function authorizeChat(Conversation $conversation, User $user): void
    {
        abort_unless($conversation->type->isBite(), 404);

        $this->authorize('view', $conversation);
    }

    private function ensureAvailable(Workspace $workspace, User $user): void
    {
        abort_unless($this->summons->availableTo($workspace, $user), 404);
    }
}
