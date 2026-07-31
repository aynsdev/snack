<?php

namespace App\Http\Requests\Uploads;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * One file, on its way to the composer's attachment tray.
 *
 * Authorization is the workspace middleware's: `ResolveWorkspace` has already
 * rejected anyone who is not an active member, and there is no narrower question
 * to ask here — the file is not attached to anything yet, so there is no channel
 * or conversation whose policy could apply. That check happens when the message
 * is actually sent, against the container the route names.
 *
 * # What is refused
 *
 * A deny list rather than an allow list. "Documents people share at work" is a
 * list nobody can finish, and every omission reads to the user as a broken
 * product. What has to be refused is the much shorter list of things a browser
 * or an operating system will *execute* — so that is what is enumerated, and it
 * is checked against the extension *and* the detected MIME type, because either
 * alone is trivially wrong: a client sends whatever MIME type it likes, and an
 * extension is just the tail of a string.
 */
class StoreUploadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.config('snack.uploads.max_size_kb', 51_200),
                $this->notExecutable(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to upload.',
            'file.max' => 'That file is larger than :max KB.',
            'file.uploaded' => 'That file could not be uploaded. It may be larger than the server allows.',
        ];
    }

    /**
     * The validated upload.
     *
     * Named `upload()` rather than `file()` because `Request::file()` already
     * exists with a different signature and return type — overriding it would
     * break every caller that expected the framework's.
     */
    public function upload(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function notExecutable(): Closure
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
