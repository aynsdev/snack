<?php

namespace App\Http\Resources;

use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * A file as delivered to the client.
 *
 * Mirrors the `Attachment` type in resources/js/types/snack.ts.
 *
 * # No paths, ever
 *
 * `disk`, `path`, and `thumbnail_path` are hidden on the model and absent here.
 * What the client gets instead are signed URLs to routes that re-authorize the
 * viewer against the message's container on every hit — the signature bounds how
 * long a copied link keeps working, but it is not what grants access.
 *
 * # Why the expiry is rounded
 *
 * A signed URL embeds an absolute timestamp, so a naive `now()->addMinutes(30)`
 * produces a different URL every single render — and a different URL is a cache
 * miss, which for a timeline of images means re-downloading every picture on
 * every page load. Snapping the expiry to a fixed window makes the URL stable
 * for everyone who renders the same file within it, so the browser cache works.
 *
 * # Why these routes are relative and not workspace-prefixed
 *
 * Every other route in the app carries `w/{slug}`, so the tenant is settled
 * before the controller runs. A file link cannot: it is minted by a queued
 * broadcast that has no request and therefore no resolved workspace, and it
 * would break the moment a workspace was renamed. The URL is relative for the
 * same queue-related reason: a worker cannot know whether the browser reached
 * the app over HTTP or HTTPS. Baking in `APP_URL` made live-arriving attachment
 * previews use HTTP on an HTTPS page, which browsers correctly block as mixed
 * content. A relative signed URL inherits the page's origin while keeping the
 * path and query tamper-proof. Access is still derived from the message the file
 * hangs off — the same container policy that decides whether the message is
 * visible decides whether its files are.
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::payload($this->resource);
    }

    /**
     * Shared with the broadcast payload, so a file looks identical whether it
     * arrived over HTTP or over the WebSocket.
     *
     * @return array<string, mixed>
     */
    public static function payload(MessageAttachment $attachment): array
    {
        $isImage = $attachment->isImage();
        $metadata = $attachment->metadata;

        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'human_size' => $attachment->humanSize(),
            'kind' => $attachment->kind(),

            'width' => isset($metadata['width']) ? (int) $metadata['width'] : null,
            'height' => isset($metadata['height']) ? (int) $metadata['height'] : null,

            /*
             * Whether the file moves. The client uses it to choose the original
             * over the generated thumbnail, which is a single frozen frame.
             * False until the queue has looked at the upload, so a GIF plays a
             * moment after it is sent rather than never.
             */
            'is_animated' => (bool) ($metadata['animated'] ?? false),

            'download_url' => self::signed('files.show', $attachment),

            /*
             * Null until the queue has processed the upload, which is what the
             * client's shimmer placeholder waits on. A non-image never gets one,
             * and neither does an image GD could not decode — both fall back to
             * the file chip, which is the same control.
             */
            'thumbnail_url' => $attachment->hasThumbnail()
                ? self::signed('files.thumbnail', $attachment)
                : null,

            /*
             * The full-size original, offered inline only below a size ceiling.
             * Images and PDFs — the two formats the client can preview in a
             * dialog. Above the ceiling the browser shows a chip: a 40 MB PNG is
             * not a preview, it is a download that started on its own.
             */
            'preview_url' => ($isImage || $attachment->isPdf()) && $attachment->size <= (int) config('snack.uploads.inline_preview_max_size')
                ? self::signed('files.show', $attachment, inline: true)
                : null,

            /*
             * Where an inline player reads a video from. No size ceiling: the
             * player asks for byte ranges, so a 200 MB clip costs its metadata
             * until somebody presses play — unlike an image, which is fetched
             * whole the moment it scrolls into view.
             */
            'stream_url' => $attachment->isPlayableVideo()
                ? self::signed('files.play', $attachment)
                : null,

            'created_at' => $attachment->created_at?->toIso8601String(),
        ];
    }

    /**
     * A signed URL valid until the end of the current window.
     */
    private static function signed(string $route, MessageAttachment $attachment, bool $inline = false): string
    {
        return URL::temporarySignedRoute($route, self::expiry(), [
            'attachment' => $attachment->id,
            ...($inline ? ['inline' => 1] : []),
        ], absolute: false);
    }

    /**
     * The end of the current fixed window, plus one more window of slack.
     *
     * The slack is what stops a link minted at 10:29:59 from expiring a second
     * later: without it, every URL rendered near a boundary is dead on arrival.
     */
    private static function expiry(): Carbon
    {
        $minutes = max(1, (int) config('snack.uploads.download_link_ttl_minutes', 30));
        $seconds = $minutes * 60;

        return Carbon::createFromTimestamp(
            (int) (ceil(now()->getTimestamp() / $seconds) * $seconds) + $seconds,
        );
    }
}
