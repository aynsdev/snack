<?php

namespace App\Services;

use App\Enums\BiteCapability;
use App\Enums\GenerationStatus;
use App\Enums\MagnificModel;
use App\Enums\MediaKind;
use App\Http\Resources\AttachmentResource;
use App\Models\BiteGeneration;
use App\Models\BiteSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * What the Bite page shows about a person's chats and the media Bite made them.
 *
 * Shared by the web page and the mobile API so both list the same chats, in the
 * same order, offer the same models and show the same generations — the two
 * clients disagreeing about which chats exist would be a bug neither could see.
 */
class BiteChats
{
    /**
     * How many chats the sidebar lists. Older ones are still reachable by link
     * and by search.
     */
    private const SIDEBAR_LIMIT = 100;

    /**
     * How many generations one page of the Media tab holds.
     */
    private const MEDIA_PER_PAGE = 48;

    /**
     * @return array<int, array{id: string, title: string, last_message_at: string|null, created_at: string|null, is_pinned: bool, is_archived: bool, share_url: string|null}>
     */
    public function chatsFor(Workspace $workspace, User $user, bool $archived = false): array
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
            ->map(fn (Conversation $chat): array => $this->summary($chat, $workspace))
            ->all();
    }

    /**
     * One row of the chat list.
     *
     * `share_url` is the web page's address on purpose: it is the link a person
     * pastes to a colleague, whichever client they copied it from.
     *
     * @return array{id: string, title: string, last_message_at: string|null, created_at: string|null, is_pinned: bool, is_archived: bool, share_url: string|null}
     */
    public function summary(Conversation $chat, Workspace $workspace): array
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
     * The image and video models this workspace lets Bite generate with.
     *
     * @return array<int, array{id: string, label: string, kind: string, description: string}>
     */
    public function mediaModels(Workspace $workspace): array
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
     * The generations still being made for a chat, so it can show placeholders.
     *
     * @return array<int, array{id: int, kind: string, model: string, prompt: string, status: string, aspect_ratio: string|null}>
     */
    public function pendingGenerations(Workspace $workspace, Conversation $conversation, User $user): array
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

    /**
     * One page of the Media tab: every image and video Bite made for this person.
     *
     * Read from {@see BiteGeneration} rather than by searching attachments,
     * because only the generation knows the prompt and the model — the two
     * things worth showing beside a picture — and which ones are still being
     * made.
     *
     * @return array{data: array<int, array<string, mixed>>, next_page_url: string|null, prev_page_url: string|null}
     */
    public function media(Workspace $workspace, User $user, ?MediaKind $kind, Request $request): array
    {
        $generations = BiteGeneration::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            /*
             * Finished-and-deleted rows are left out: their file went with the
             * chat it was posted in. In-progress rows stay, so a video that is
             * still rendering shows as a placeholder rather than not at all.
             */
            ->where(fn ($query) => $query
                ->whereIn('status', [GenerationStatus::Pending, GenerationStatus::Processing])
                ->orWhereNotNull('message_attachment_id'))
            ->with(['attachment', 'requestMessage.conversation'])
            ->latest('id')
            ->paginate(self::MEDIA_PER_PAGE)
            ->withQueryString();

        return [
            'data' => $generations->getCollection()->map(fn (BiteGeneration $generation): array => [
                'id' => $generation->id,
                'kind' => $generation->kind->value,
                'model' => $generation->model->label(),
                'prompt' => $generation->prompt,
                'status' => $generation->status->value,
                'created_at' => $generation->created_at?->toIso8601String(),
                'attachment' => $generation->attachment
                    ? (new AttachmentResource($generation->attachment))->resolve($request)
                    : null,
                'chat_id' => $generation->requestMessage?->conversation?->type->isBite()
                    ? $generation->requestMessage->conversation->public_id
                    : null,
            ])->all(),
            'next_page_url' => $generations->nextPageUrl(),
            'prev_page_url' => $generations->previousPageUrl(),
        ];
    }
}
