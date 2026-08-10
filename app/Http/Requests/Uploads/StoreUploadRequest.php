<?php

namespace App\Http\Requests\Uploads;

use App\Http\Requests\Concerns\RefusesExecutableUploads;
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
 * A deny list rather than an allow list — see {@see RefusesExecutableUploads},
 * which is shared with the asset library so the rule exists once.
 */
class StoreUploadRequest extends FormRequest
{
    use RefusesExecutableUploads;

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
}
