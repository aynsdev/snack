<?php

namespace App\Http\Controllers\Bite;

use App\Actions\Bite\StartBiteChat;
use App\Actions\Conversations\SendConversationMessage;
use App\Enums\BiteCapability;
use App\Enums\GenerationStatus;
use App\Enums\MagnificModel;
use App\Enums\MediaKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bite\StoreBiteChatRequest;
use App\Http\Requests\Bite\UpdateBiteChatRequest;
use App\Http\Resources\ConversationResource;
use App\Models\BiteGeneration;
use App\Models\BiteSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BiteSummons;
use App\Services\Magnific;
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
    /**
     * How many chats the sidebar lists. Older ones are still reachable by link
     * and by search.
     */
    private const SIDEBAR_LIMIT = 100;

    public function __construct(
        private readonly BiteSummons $summons,
        private readonly MessageHistory $history,
    ) {}

    /**
     * The empty "new chat" state.
     */
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->ensureAvailable($workspace, $request->user());

        return Inertia::render('bite/show', [
            'biteChats' => $this->chatsFor($workspace, $request->user(), $request->boolean('archived')),
            'biteArchived' => $request->boolean('archived'),
            'mediaModels' => fn () => $this->mediaModels($workspace),
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
            'biteChats' => fn () => $this->chatsFor($workspace, $user, $conversation->archived_at !== null),
            'biteArchived' => $conversation->archived_at !== null,
            'chatMenu' => fn () => $this->chatSummary($conversation, $workspace),
            'mediaModels' => fn () => $this->mediaModels($workspace),
            'chat' => fn () => (new ConversationResource($conversation))->resolve($request),
            'history' => fn () => $this->history->page($conversation),
            'mediaGenerations' => fn () => $this->pendingGenerations($workspace, $conversation, $user),
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

    /**
     * @return array<int, array{id: string, title: string, last_message_at: string|null, created_at: string|null, is_pinned: bool, is_archived: bool, share_url: string|null}>
     */
    private function chatsFor(Workspace $workspace, User $user, bool $archived = false): array
    {
        return $workspace->conversations()
            ->biteChats()
            ->visibleTo($user)
            ->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->orderByDesc('is_pinned')
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('id')
            ->limit(self::SIDEBAR_LIMIT)
            ->get(['id', 'public_id', 'name', 'last_message_at', 'created_at', 'is_pinned', 'archived_at', 'share_token'])
            ->map(fn (Conversation $chat): array => $this->chatSummary($chat, $workspace))
            ->all();
    }

    /** @return array{id: string, title: string, last_message_at: string|null, created_at: string|null, is_pinned: bool, is_archived: bool, share_url: string|null} */
    private function chatSummary(Conversation $chat, Workspace $workspace): array
    {
        return [
            'id' => $chat->public_id,
            'title' => $chat->name ?? 'New chat',
            'is_pinned' => $chat->is_pinned,
            'is_archived' => $chat->archived_at !== null,
            'share_url' => $chat->share_token === null ? null : route('bite.shared.show', ['workspace' => $workspace->slug, 'token' => $chat->share_token]),
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'created_at' => $chat->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{id: string, label: string, kind: string, description: string}>
     */
    private function mediaModels(Workspace $workspace): array
    {
        if (! Magnific::isConfigured()) {
            return [];
        }

        $settings = BiteSetting::forWorkspace($workspace);

        return array_values(array_map(
            fn (MagnificModel $model): array => [
                'id' => $model->value,
                'label' => $model->label(),
                'kind' => $model->kind()->value,
                'description' => $model->summary(),
            ],
            array_filter(MagnificModel::cases(), fn (MagnificModel $model): bool => $settings->allows(
                $model->kind() === MediaKind::Image ? BiteCapability::GenerateImage : BiteCapability::GenerateVideo,
            )),
        ));
    }

    /**
     * @return array<int, array{id: int, kind: string, model: string, prompt: string, status: string, aspect_ratio: string|null}>
     */
    private function pendingGenerations(Workspace $workspace, Conversation $conversation, User $user): array
    {
        return BiteGeneration::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereIn('status', [GenerationStatus::Pending, GenerationStatus::Processing])
            ->whereHas('requestMessage', fn ($query) => $query
                ->where('conversation_id', $conversation->id)
                ->whereNull('parent_message_id'))
            ->oldest('id')
            ->get(['id', 'kind', 'model', 'prompt', 'status', 'aspect_ratio'])
            ->map(fn (BiteGeneration $generation): array => [
                'id' => $generation->id,
                'kind' => $generation->kind->value,
                'model' => $generation->model->label(),
                'prompt' => $generation->prompt,
                'status' => $generation->status->value,
                'aspect_ratio' => $generation->aspect_ratio,
            ])->all();
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
