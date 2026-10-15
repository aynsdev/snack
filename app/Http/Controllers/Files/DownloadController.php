<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Services\BiteChatSharing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves attachment bytes.
 *
 * # The signature is not the authorization
 *
 * These routes are signed, which bounds how long a copied link keeps working.
 * That is all it does. Every hit still re-resolves the attachment, finds the
 * message it hangs off, and puts that message through the container policy — so
 * a link forwarded to someone who was never in the channel is a 404 no matter
 * how valid its signature is, and a link that outlives the sender's removal from
 * a private channel stops working for them too.
 *
 * # Inline is a narrower door than download
 *
 * Anything served inline renders *on this application's origin*, so a document
 * that can carry script would be stored XSS against every viewer. Only a fixed
 * list of formats is ever given an inline disposition — images, because they
 * are rendered through `<img>` and sandboxed, and PDFs, because browsers
 * hand them to a built-in viewer rather than executing them as HTML.
 * Everything else is an attachment, which browsers download rather than
 * execute. `nosniff` and a sandboxing CSP back that up for the cases where a
 * browser would otherwise go looking for a better content type than the one
 * it was given.
 */
class DownloadController extends Controller
{
    /**
     * Image formats it is safe to render on our own origin.
     *
     * # Why SVG is on this list
     *
     * It was not, and the reasoning was sound as far as it went: an SVG is a
     * document that can carry script, and one rendered on this origin with
     * nothing else in the way is stored XSS. What that missed is that half the
     * asset library is SVG — a logo is *the* thing a company files — and every
     * one of them showed up as a grey placeholder icon in a gallery whose entire
     * purpose is recognising artwork by looking at it.
     *
     * Two independent things make it safe here, and the second is the one that
     * matters. Every surface that renders one does so through an `<img>`, and an
     * SVG loaded as an image never runs its script — but that is the caller's
     * behaviour, and this class cannot depend on every future caller keeping to
     * it. The response itself therefore carries `Content-Security-Policy:
     * default-src 'none'; sandbox`, which is what makes a *direct navigation* to
     * the URL harmless too: `sandbox` with no `allow-scripts` token means no
     * script executes, and with no `allow-same-origin` token the document sits
     * in an opaque origin with no reach into this one's cookies or storage.
     *
     * That header is not new — {@see stream()} has always sent it — which is
     * precisely why adding SVG here is a smaller change than it looks. Paired
     * with `nosniff` and an explicit `Content-Type`, the file is rendered as the
     * picture it claims to be and as nothing else.
     *
     * @var array<int, string>
     */
    private const INLINE_SAFE = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/svg+xml',
        'application/pdf',
    ];

    /**
     * The original file, as an attachment or — for images the client asked to
     * preview — inline.
     */
    public function show(MessageAttachment $attachment, Request $request): StreamedResponse
    {
        $this->authorizeAccess($attachment, $request);

        $inline = $request->boolean('inline')
            && in_array(strtolower($attachment->mime_type), self::INLINE_SAFE, true);

        return $this->stream(
            $attachment,
            $attachment->path,
            $attachment->mime_type,
            $inline,
        );
    }

    /**
     * The generated preview, which is always one of our own re-encoded rasters
     * and therefore always safe inline.
     */
    public function thumbnail(MessageAttachment $attachment, Request $request): StreamedResponse
    {
        $this->authorizeAccess($attachment, $request);

        abort_if($attachment->thumbnail_path === null, 404);

        return $this->stream(
            $attachment,
            $attachment->thumbnail_path,
            // Written by ImageProcessor, which emits only these two.
            str_ends_with($attachment->thumbnail_path, '.png') ? 'image/png' : 'image/jpeg',
            inline: true,
        );
    }

    /**
     * A video, for the timeline's inline player.
     *
     * # Why not {@see show()}
     *
     * A player seeks with `Range` requests, and Safari refuses to play at all
     * from a server that does not answer them. `show()` streams the file from
     * the first byte to the last, so it can never return a partial response.
     *
     * Object storage answers ranges itself, so a cloud disk redirects to a
     * short-lived URL on the bucket. That URL lives on the bucket's domain, not
     * this origin, so the sandbox headers `show()` sends are not needed there.
     * A local disk hands back the file itself, which Symfony serves with range
     * support built in.
     */
    public function play(MessageAttachment $attachment, Request $request): Response
    {
        $this->authorizeAccess($attachment, $request);

        abort_unless($attachment->isPlayableVideo(), 404);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        if (config("filesystems.disks.{$attachment->disk}.driver") === 'local') {
            return response()->file($disk->path($attachment->path), [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        $url = $disk->temporaryUrl(
            $attachment->path,
            now()->addMinutes((int) config('snack.uploads.stream_url_ttl_minutes', 120)),
            [
                'ResponseContentType' => $attachment->mime_type,
                'ResponseContentDisposition' => 'inline',
            ],
        );

        // Never cached: the redirect target expires, and a cached hop to a
        // dead URL would break the player until the cache did.
        return redirect()->away($url)->header('Cache-Control', 'no-store');
    }

    /**
     * Who may read this file.
     *
     * An unattached upload belongs to nobody but the person who made it — there
     * is no message yet, so there is no container policy to consult and no one
     * else with any claim to it.
     */
    private function authorizeAccess(MessageAttachment $attachment, Request $request): void
    {
        $user = $request->user();
        /*
         * A file on a card is read by whoever may read the card, which is
         * whoever may read its project — the same delegation TaskPolicy makes,
         * so a board and its files can never disagree about who sees them.
         */
        if ($attachment->task_id !== null) {
            $task = $attachment->task;

            abort_if($task === null, 404);

            Gate::authorize('view', $task);

            return;
        }

        /*
         * A file in the asset library is read by whoever may read the asset,
         * which is anybody in the workspace who is not a guest — the library is
         * the company's own shelf, and a guest is by definition somebody brought
         * in for one channel. Same delegation as the card branch above: the
         * policy answers once, and the download cannot disagree with the page
         * that offered the link.
         */
        if ($attachment->asset_id !== null) {
            $asset = $attachment->asset;

            abort_if($asset === null, 404);

            Gate::authorize('view', $asset);

            return;
        }

        if ($attachment->canvas_id !== null) {
            $canvas = $attachment->canvas;

            abort_if($canvas === null, 404);

            Gate::authorize('view', $canvas);

            return;
        }

        if ($attachment->message_id === null) {
            abort_unless($attachment->uploaded_by === $user->id, 404);

            return;
        }

        // Null when the message was soft-deleted: the relation carries Message's
        // global scope, so a deleted message takes its files with it.
        $message = $attachment->message;

        abort_if($message === null, 404);

        $shareToken = $request->query('share');

        if (is_string($shareToken) && app(BiteChatSharing::class)->allowsFile($user, $message, $shareToken)) {
            return;
        }

        Gate::authorize('view', $message);
    }

    private function stream(
        MessageAttachment $attachment,
        string $path,
        string $contentType,
        bool $inline,
    ): StreamedResponse {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($path), 404);

        return $disk->response(
            $path,
            $attachment->original_name,
            [
                'Content-Type' => $contentType,
                // Stops a browser from deciding the bytes are really HTML.
                'X-Content-Type-Options' => 'nosniff',
                // Belt and braces for the inline case: even if something did get
                // rendered as a document, it can load nothing and run nothing.
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                /*
                 * Private, because the URL is per-viewer authorized and must not
                 * be held by a shared proxy. Cached at all because the signed URL
                 * is stable within its window — see AttachmentResource — which is
                 * what stops a timeline of images re-downloading on every visit.
                 */
                'Cache-Control' => 'private, max-age=3600',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }
}
