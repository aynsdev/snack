<?php

namespace App\Ai;

use App\Enums\GenerationStatus;
use App\Enums\MagnificModel;
use App\Enums\MediaKind;
use App\Jobs\GenerateBiteMedia;
use App\Models\BiteGeneration;
use App\Models\Message;
use App\Models\MessageAttachment;

/**
 * Queue an image or video for Bite to post when it is ready.
 *
 * Shared by `generate_image` and `generate_video`, which differ only in what
 * they ask the model for. Everything slow happens in {@see GenerateBiteMedia};
 * this only writes the row and dispatches, so the tool returns in
 * milliseconds and Bite's own answer — "on it" — goes out straight away.
 */
final readonly class BiteMedia
{
    public function __construct(private BiteFiles $files) {}

    /**
     * @return BiteGeneration|string The queued generation, or why it could not be queued.
     */
    public function queue(
        BiteContext $context,
        MediaKind $kind,
        mixed $model,
        string $prompt,
        mixed $aspectRatio,
        mixed $duration = null,
        ?MessageAttachment $source = null,
    ): BiteGeneration|string {
        if ($context->message === null) {
            return 'Images and videos can only be made in a chat, where there is somewhere to post them.';
        }

        $chosen = is_string($model) ? MagnificModel::tryFrom($model) : null;

        if ($chosen !== null && $chosen->kind() !== $kind) {
            return "{$chosen->label()} makes {$chosen->kind()->value}s, not {$kind->value}s. Pick one of the listed models.";
        }

        $chosen ??= MagnificModel::defaultFor($kind);

        if ($source !== null && ! $chosen->acceptsImage()) {
            return "{$chosen->label()} cannot start from an image. Use one of the models marked \"text or image\".";
        }

        $generation = BiteGeneration::create([
            'workspace_id' => $context->workspace->id,
            'user_id' => $context->user->id,
            'request_message_id' => $context->message->id,
            'kind' => $kind,
            'model' => $chosen,
            'prompt' => $prompt,
            'aspect_ratio' => in_array($aspectRatio, MagnificModel::ASPECT_RATIOS, true) ? $aspectRatio : null,
            'duration' => $kind === MediaKind::Video
                ? $chosen->durationFor(is_numeric($duration) ? (int) $duration : null)
                : null,
            'source_attachment_id' => $source?->id,
            'status' => GenerationStatus::Pending,
        ]);

        GenerateBiteMedia::dispatch($generation);

        return $generation;
    }

    /**
     * The image a video should start from.
     *
     * In order: a file the model named by id (re-resolved against the asker, as
     * every model-supplied id is — see {@see BiteFiles}); an image attached to
     * the message that asked; or, when asked to, the newest image earlier in the
     * same chat — which is how "now animate it" finds the picture Bite just made.
     */
    public function sourceImage(BiteContext $context, mixed $fileId, bool $usePrevious): MessageAttachment|string|null
    {
        if ($fileId !== null && $fileId !== '') {
            $file = $this->files->resolve($context, $fileId);

            if ($file === null || ! $file->isImage()) {
                return 'There is no image with that id that this person can read.';
            }

            return $file;
        }

        $attached = $context->message?->attachments->first(fn (MessageAttachment $file): bool => $file->isImage());

        if ($attached !== null) {
            return $attached;
        }

        if (! $usePrevious || $context->message === null) {
            return null;
        }

        return $this->latestImageBefore($context->message)
            ?? 'There is no earlier image in this chat to start from.';
    }

    private function latestImageBefore(Message $message): ?MessageAttachment
    {
        return MessageAttachment::query()
            ->where('mime_type', 'like', 'image/%')
            ->whereHas('message', function ($query) use ($message): void {
                $query->where('id', '<', $message->id)
                    ->when(
                        $message->channel_id !== null,
                        fn ($query) => $query->where('channel_id', $message->channel_id),
                        fn ($query) => $query->where('conversation_id', $message->conversation_id),
                    );
            })
            ->latest('id')
            ->first();
    }
}
