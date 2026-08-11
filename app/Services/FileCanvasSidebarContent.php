<?php

namespace App\Services;

use App\Http\Resources\AttachmentResource;
use App\Models\Canvas;
use App\Models\FileCanvasStar;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FileCanvasSidebarContent
{
    public function __construct(
        private readonly MessageVisibility $messageVisibility,
        private readonly CanvasVisibility $canvasVisibility,
    ) {}

    /**
     * @return array{recent: array<int, array<string, mixed>>, starred: array<int, array<string, mixed>>}
     */
    public function for(Workspace $workspace, User $user, int $limit = 8): array
    {
        $stars = FileCanvasStar::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->latest('updated_at')
            ->get(['canvas_id', 'message_attachment_id', 'updated_at']);

        $canvasStarredAt = $stars
            ->whereNotNull('canvas_id')
            ->mapWithKeys(fn (FileCanvasStar $star): array => [
                (int) $star->canvas_id => $star->updated_at?->getTimestamp() ?? 0,
            ]);
        $fileStarredAt = $stars
            ->whereNotNull('message_attachment_id')
            ->mapWithKeys(fn (FileCanvasStar $star): array => [
                (int) $star->message_attachment_id => $star->updated_at?->getTimestamp() ?? 0,
            ]);

        return [
            'recent' => $this->recent($workspace, $user, $canvasStarredAt, $fileStarredAt, $limit),
            'starred' => $this->starred($workspace, $user, $canvasStarredAt, $fileStarredAt, $limit),
        ];
    }

    /**
     * @param  Collection<int, int>  $canvasStarredAt
     * @param  Collection<int, int>  $fileStarredAt
     * @return array<int, array<string, mixed>>
     */
    private function recent(
        Workspace $workspace,
        User $user,
        Collection $canvasStarredAt,
        Collection $fileStarredAt,
        int $limit,
    ): array {
        $files = $this->files($workspace, $user)
            ->latest('id')
            ->limit($limit)
            ->get($this->fileColumns())
            ->map(fn (MessageAttachment $file): array => $this->fileItem(
                $file,
                $fileStarredAt->has($file->id),
                $file->created_at?->getTimestamp() ?? 0,
            ));

        $canvases = $this->canvasVisibility
            ->query($workspace, $user)
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'public_id', 'workspace_id', 'title', 'updated_at'])
            ->map(fn (Canvas $canvas): array => $this->canvasItem(
                $workspace,
                $canvas,
                $canvasStarredAt->has($canvas->id),
                $canvas->updated_at?->getTimestamp() ?? 0,
            ));

        return $this->withoutTimestamps(
            $files->concat($canvases)->sortByDesc('timestamp')->take($limit)->values(),
        );
    }

    /**
     * @param  Collection<int, int>  $canvasStarredAt
     * @param  Collection<int, int>  $fileStarredAt
     * @return array<int, array<string, mixed>>
     */
    private function starred(
        Workspace $workspace,
        User $user,
        Collection $canvasStarredAt,
        Collection $fileStarredAt,
        int $limit,
    ): array {
        $files = $this->files($workspace, $user)
            ->whereIn('id', $fileStarredAt->keys())
            ->get($this->fileColumns())
            ->map(fn (MessageAttachment $file): array => $this->fileItem(
                $file,
                true,
                (int) $fileStarredAt->get($file->id, 0),
            ));

        $canvases = $this->canvasVisibility
            ->query($workspace, $user)
            ->whereIn('id', $canvasStarredAt->keys())
            ->get(['id', 'public_id', 'workspace_id', 'title', 'updated_at'])
            ->map(fn (Canvas $canvas): array => $this->canvasItem(
                $workspace,
                $canvas,
                true,
                (int) $canvasStarredAt->get($canvas->id, 0),
            ));

        return $this->withoutTimestamps(
            $files->concat($canvases)->sortByDesc('timestamp')->take($limit)->values(),
        );
    }

    /** @return Builder<MessageAttachment> */
    private function files(Workspace $workspace, User $user): Builder
    {
        return MessageAttachment::query()
            ->where('workspace_id', $workspace->id)
            ->attached()
            ->whereHas(
                'message',
                fn (Builder $message) => $this->messageVisibility->apply($message, $workspace, $user),
            );
    }

    /** @return array<int, string> */
    private function fileColumns(): array
    {
        return [
            'id', 'original_name', 'mime_type', 'size', 'metadata', 'thumbnail_path', 'created_at',
        ];
    }

    /** @return array<string, mixed> */
    private function fileItem(MessageAttachment $file, bool $isStarred, int $timestamp): array
    {
        return [
            'id' => 'file:'.$file->id,
            'target_id' => $file->id,
            'type' => 'file',
            'label' => $file->original_name,
            'url' => (string) AttachmentResource::payload($file)['download_url'],
            'is_starred' => $isStarred,
            'timestamp' => $timestamp,
        ];
    }

    /** @return array<string, mixed> */
    private function canvasItem(
        Workspace $workspace,
        Canvas $canvas,
        bool $isStarred,
        int $timestamp,
    ): array {
        return [
            'id' => 'canvas:'.$canvas->public_id,
            'target_id' => $canvas->public_id,
            'type' => 'canvas',
            'label' => $canvas->title,
            'url' => route('canvases.show', [$workspace, $canvas]),
            'is_starred' => $isStarred,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function withoutTimestamps(Collection $items): array
    {
        return $items
            ->map(function (array $item): array {
                unset($item['timestamp']);

                return $item;
            })
            ->all();
    }
}
