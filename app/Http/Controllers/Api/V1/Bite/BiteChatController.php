<?php

namespace App\Http\Controllers\Api\V1\Bite;

use App\Actions\Bite\StartBiteChat;
use App\Actions\Conversations\SendConversationMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bite\StoreBiteChatRequest;
use App\Http\Requests\Bite\UpdateBiteChatRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\BiteChats;
use App\Services\BiteSummons;
use App\Services\MessageHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * A person's own chats with Bite, for the mobile app.
 *
 * Each chat is a conversation of type `bite`, so its messages, read pointer and
 * broadcasts go through the `dms/{conversation}/...` endpoints like any DM. This
 * controller only lists, opens, starts, organizes and deletes them.
 */
class BiteChatController extends Controller
{
    public function __construct(
        private readonly BiteChats $chats,
        private readonly BiteSummons $summons,
        private readonly MessageHistory $history,
    ) {}

    /**
     * The chat list — `?archived=1` for the archived ones — and the models a
     * new chat may generate media with.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        abort_unless($this->summons->availableTo($workspace, $request->user()), 404);

        return response()->json([
            'data' => [
                'chats' => $this->chats->chatsFor($workspace, $request->user(), $request->boolean('archived')),
                'archived' => $request->boolean('archived'),
                'media_models' => $this->chats->mediaModels($workspace),
                'can_post' => true,
            ],
        ]);
    }

    public function show(Workspace $workspace, Conversation $conversation, Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($conversation->type->isBite(), 404);
        $this->authorize('view', $conversation);

        $conversation->load([
            'activeUsers',
            'participants' => fn ($query) => $query->where('user_id', $user->id),
        ]);

        return response()->json([
            'data' => [
                'chat' => (new ConversationResource($conversation))->resolve($request),
                'summary' => $this->chats->summary($conversation, $workspace),
                'media_models' => $this->chats->mediaModels($workspace),
                'history' => $this->history->page($conversation),
                'media_generations' => $this->chats->pendingGenerations($workspace, $conversation, $user),
                'can_post' => $user->can('post', $conversation) && $this->summons->availableTo($workspace, $user),
            ],
        ]);
    }

    /**
     * Start a chat with its first message — one request, so there is never a
     * chat in the list that nobody asked anything in.
     */
    public function store(
        Workspace $workspace,
        StoreBiteChatRequest $request,
        StartBiteChat $start,
        SendConversationMessage $send,
    ): JsonResponse {
        $conversation = $start->handle($workspace, $request->user(), $request->messageBody());

        $message = $send->handle(
            conversation: $conversation,
            sender: $request->user(),
            body: $request->messageBody(),
            attachments: $request->attachments(),
            biteModel: $request->biteModel(),
        );

        return response()->json([
            'data' => [
                'chat' => $this->chats->summary($conversation->refresh(), $workspace),
                'message' => (new MessageResource($message))->resolve($request),
            ],
        ], 201);
    }

    /**
     * Retitle, pin or archive a chat. Not through RenameConversation, whose
     * system message is noise in a chat only its owner reads.
     */
    public function update(Workspace $workspace, Conversation $conversation, UpdateBiteChatRequest $request): JsonResponse
    {
        $conversation->forceFill($request->chatAttributes())->save();

        return response()->json(['data' => $this->chats->summary($conversation, $workspace)]);
    }

    /**
     * Delete a chat and everything in it, including the media posted in it.
     */
    public function destroy(Workspace $workspace, Conversation $conversation): Response
    {
        $this->authorize('delete', $conversation);

        DB::transaction(fn () => $conversation->delete());

        return response()->noContent();
    }
}
