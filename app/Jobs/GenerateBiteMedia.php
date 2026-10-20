<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Enums\MediaKind;
use App\Exceptions\GenerationFailed;
use App\Models\BiteGeneration;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\BiteBot;
use App\Services\ImageProcessor;
use App\Services\Magnific;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Make one image or video with Magnific and post it where it was asked for.
 *
 * # Re-queued rather than held
 *
 * A video takes minutes. Rather than hold a worker for all of them, the job
 * submits the task, then releases itself back onto the queue to look again
 * every few seconds — each run is one short HTTP call. `retryUntil()` rather
 * than `$tries` bounds it, because every release counts as an attempt and the
 * number of checks a video needs is not knowable up front.
 *
 * # Every outcome is posted
 *
 * Somebody asked for this and was told it was on its way. A result, a failure,
 * and a timeout all end in a message from Bite in the same chat — a request
 * that silently never answers is the worst of the three.
 */
class GenerateBiteMedia implements ShouldQueue
{
    use Queueable;

    /**
     * How long between checks on a running task. Most images are ready in
     * seconds, so they are checked often; a video takes minutes, and checking
     * it as often would only cost requests.
     */
    public const IMAGE_POLL_SECONDS = 2;

    public const VIDEO_POLL_SECONDS = 5;

    /**
     * The longest edge a source or reference image is sent at. Large enough
     * for any model here to read detail from, small enough that a phone photo
     * is not uploaded as megabytes of base64 on every submit.
     */
    public const SOURCE_MAX_EDGE = 2048;

    public int $timeout = 150;

    public function __construct(public BiteGeneration $generation) {}

    public function retryUntil(): \DateTimeInterface
    {
        return $this->generation->created_at->addMinutes($this->budgetMinutes() + 5);
    }

    public function pollSeconds(): int
    {
        return $this->generation->kind === MediaKind::Video ? self::VIDEO_POLL_SECONDS : self::IMAGE_POLL_SECONDS;
    }

    public function handle(Magnific $magnific, BiteBot $bots, ImageProcessor $images): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status->isFinished()) {
            return;
        }

        $this->generation = $generation;

        try {
            if ($generation->task_id === null) {
                $this->submit($magnific, $images);

                $this->release($this->pollSeconds());

                return;
            }

            $task = $magnific->check($generation->model, $generation->hasSourceImage(), $generation->task_id);

            if ($task['status'] === 'FAILED') {
                throw new GenerationFailed('Magnific could not generate it.');
            }

            if ($task['status'] !== 'COMPLETED') {
                if ($generation->created_at->addMinutes($this->budgetMinutes())->isPast()) {
                    throw new GenerationFailed('It took too long and was abandoned.');
                }

                $this->release($this->pollSeconds());

                return;
            }

            if ($task['url'] === null) {
                throw new GenerationFailed('Magnific finished without a result.');
            }

            $this->deliver($magnific, $bots, $task['url']);
        } catch (GenerationFailed $exception) {
            $this->giveUp($bots, $exception->getMessage());
        } catch (Throwable $exception) {
            /*
             * Anything that is not a GenerationFailed was written for a
             * developer, not for the chat — a storage error names the bucket,
             * a database error the query. Logged in full, answered in general.
             */
            report($exception);

            $this->giveUp($bots, 'Something went wrong on our side. An admin can find the details in the logs.');
        }
    }

    /**
     * Whatever went wrong outside the expected failures — a crashed worker, a
     * timeout of this job itself — still has to end in an answer.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status->isFinished()) {
            return;
        }

        $this->generation = $generation;

        $this->giveUp(app(BiteBot::class), 'Something went wrong while generating it.');
    }

    private function submit(Magnific $magnific, ImageProcessor $images): void
    {
        $generation = $this->generation;

        $taskId = $magnific->submit(
            $generation->model,
            $generation->model->payload(
                $generation->prompt,
                $generation->aspect_ratio,
                $generation->duration,
                $this->sourceImage($images),
            ),
            $generation->hasSourceImage(),
        );

        $generation->update(['task_id' => $taskId, 'status' => GenerationStatus::Processing]);
    }

    /**
     * The source or reference image, base64-encoded as Magnific accepts it.
     *
     * Scaled down and re-encoded when it is larger than {@see SOURCE_MAX_EDGE}
     * or in a format not every model reads (only JPEG and PNG are): smaller to
     * send, and readable everywhere. Anything else goes as it is — decoding a
     * photo costs a worker tens of megabytes, worth it only when it changes the
     * result. When it cannot be decoded, the original goes and Magnific decides.
     */
    private function sourceImage(ImageProcessor $images): ?string
    {
        $source = $this->generation->sourceAttachment;

        if ($source === null) {
            return null;
        }

        try {
            $bytes = Storage::disk($source->disk)->get($source->path);
        } catch (Throwable $exception) {
            report($exception);

            $bytes = null;
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new GenerationFailed('The image it was based on could not be read.');
        }

        if ($this->isSendableAsIs($bytes)) {
            return base64_encode($bytes);
        }

        return base64_encode($images->fit($bytes, self::SOURCE_MAX_EDGE)?->contents ?? $bytes);
    }

    /**
     * A JPEG or PNG within the size limit, read from its header alone.
     */
    private function isSendableAsIs(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);

        return $info !== false
            && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            && max($info[0], $info[1]) <= self::SOURCE_MAX_EDGE;
    }

    private function deliver(Magnific $magnific, BiteBot $bots, string $url): void
    {
        $generation = $this->generation;
        $file = $magnific->download($url);

        /*
         * A CDN that answers `application/octet-stream` would otherwise land
         * as a file chip rather than an image or a player.
         */
        $mimeType = str_starts_with($file['mime_type'], $generation->kind->value.'/')
            ? $file['mime_type']
            : ($generation->kind === MediaKind::Video ? 'video/mp4' : 'image/png');

        $attachment = $this->store($file['bytes'], $mimeType);

        $message = $this->post($bots, $this->caption(), collect([$attachment]));

        $generation->update([
            'status' => GenerationStatus::Completed,
            'message_attachment_id' => $attachment->id,
            'result_message_id' => $message?->id,
            'completed_at' => now(),
        ]);
    }

    private function giveUp(BiteBot $bots, string $reason): void
    {
        $this->generation->update([
            'status' => GenerationStatus::Failed,
            'error' => $reason,
            'completed_at' => now(),
        ]);

        $what = $this->generation->kind === MediaKind::Video ? 'video' : 'image';

        $this->post($bots, "I couldn't make that {$what}. {$reason}");
    }

    /**
     * Write the result to the uploads disk as an unclaimed attachment, the
     * shape {@see App\Actions\Messages\SendMessage} claims onto a message.
     */
    private function store(string $bytes, string $mimeType): MessageAttachment
    {
        $generation = $this->generation;
        $disk = (string) config('snack.uploads.disk', 'local');
        $extension = $this->extension($mimeType);

        $path = sprintf(
            'workspaces/%d/attachments/%s/%s.%s',
            $generation->workspace_id,
            now()->format('Y/m'),
            Str::ulid()->toBase32(),
            $extension,
        );

        try {
            $written = Storage::disk($disk)->put($path, $bytes);
        } catch (Throwable $exception) {
            report($exception);

            $written = false;
        }

        if ($written === false) {
            throw new GenerationFailed(
                'It was made, but Snack could not save the file. An admin can find the details in the logs.',
            );
        }

        $bot = app(BiteBot::class)->accountFor($generation->workspace);

        return MessageAttachment::create([
            'workspace_id' => $generation->workspace_id,
            'message_id' => null,
            'uploaded_by' => $bot->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $this->fileName($extension),
            'mime_type' => $mimeType,
            'size' => strlen($bytes),
            'metadata' => ['generation_id' => $generation->id],
        ]);
    }

    /**
     * Post into the chat the request came from: in its thread when it was a
     * reply, at the top level otherwise — the same rule Bite's answers follow.
     *
     * @param  Collection<int, MessageAttachment>|null  $attachments
     */
    private function post(BiteBot $bots, string $body, ?Collection $attachments = null): ?Message
    {
        $request = $this->generation->requestMessage;
        $container = $request?->container();

        if ($request === null || $container === null) {
            return null;
        }

        return $bots->post(
            container: $container,
            bot: $bots->accountFor($this->generation->workspace),
            body: $body,
            parent: $request->parent_message_id !== null ? $request : null,
            attachments: $attachments,
        );
    }

    private function caption(): string
    {
        $generation = $this->generation;
        $what = $generation->kind === MediaKind::Video ? 'video' : 'image';

        return "Here's your {$what}, made with {$generation->model->label()}.";
    }

    private function fileName(string $extension): string
    {
        $stem = str($this->generation->prompt)->slug()->limit(60, '')->rtrim('-')->toString();

        return ($stem === '' ? $this->generation->kind->value : $stem).'.'.$extension;
    }

    private function extension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/png' => 'png',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default => $this->generation->kind === MediaKind::Video ? 'mp4' : 'png',
        };
    }

    private function budgetMinutes(): int
    {
        return $this->generation->kind === MediaKind::Video
            ? (int) config('services.magnific.video_timeout_minutes', 20)
            : (int) config('services.magnific.image_timeout_minutes', 5);
    }
}
