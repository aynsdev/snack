<?php

namespace App\Http\Requests\Settings;

use App\Actions\Profile\UpdateAvatar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

/**
 * A new profile picture.
 *
 * `File::image()` is the rule that matters: it checks the *detected* type rather
 * than the extension or whatever the browser put in the multipart part. It also
 * excludes SVG by default, which is exactly right here — an avatar is rendered
 * inline on this application's origin, and an SVG is a document that can carry
 * script, not a picture.
 *
 * The real backstop is that {@see UpdateAvatar} re-encodes
 * the image rather than storing what arrived. Validation keeps the error message
 * useful; the re-encode is what makes the stored bytes ours.
 */
class AvatarUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                File::image()->max((int) config('snack.avatars.max_size_kb', 4096)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Choose an image to upload.',
            'avatar.image' => 'That file is not an image we can use.',
            'avatar.max' => 'That image is larger than :max KB.',
            'avatar.uploaded' => 'That image could not be uploaded. It may be larger than the server allows.',
        ];
    }

    /**
     * The validated upload.
     *
     * Named `avatar()` rather than `image()` because `Request::image()` already
     * exists and means something else entirely — overriding it would break every
     * caller that expected the framework's.
     */
    public function avatar(): ?UploadedFile
    {
        $file = $this->file('avatar');

        return $file instanceof UploadedFile ? $file : null;
    }
}
