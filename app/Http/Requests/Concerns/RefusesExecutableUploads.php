<?php

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Http\UploadedFile;

/**
 * The one thing every upload in this application is refused for.
 *
 * A deny list rather than an allow list, and deliberately so: "documents people
 * share at work" is a list nobody can finish, and every omission reads to the
 * user as a broken product. What has to be refused is the much shorter list of
 * things a browser or an operating system will *execute* — so that is what is
 * enumerated.
 *
 * Extension and MIME type are both checked, because either alone is trivially
 * wrong: a client picks whatever MIME type it feels like sending, and an
 * extension is just the tail of a string.
 *
 * Shared rather than copied. There are two doors into this application's
 * storage — the composer's tray and the asset library — and a deny list written
 * out twice is one that will be updated once.
 */
trait RefusesExecutableUploads
{
    protected function notExecutable(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $extension = strtolower($value->getClientOriginalExtension());
            $mimeType = strtolower($value->getMimeType() ?? '');

            /** @var array<int, string> $extensions */
            $extensions = config('snack.uploads.blocked_extensions', []);
            /** @var array<int, string> $mimeTypes */
            $mimeTypes = config('snack.uploads.blocked_mime_types', []);

            if (in_array($extension, $extensions, true) || in_array($mimeType, $mimeTypes, true)) {
                $fail('That kind of file cannot be shared here.');
            }
        };
    }
}
