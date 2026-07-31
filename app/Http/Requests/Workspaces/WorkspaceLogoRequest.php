<?php

namespace App\Http\Requests\Workspaces;

use App\Actions\Workspaces\UpdateWorkspaceLogo;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;

/**
 * A new workspace logo.
 *
 * `File::image()` checks the *detected* type rather than the extension or
 * whatever the browser put in the multipart part, and excludes SVG — which is
 * right here, because the logo is rendered inline on this application's origin
 * and an SVG is a document that can carry script.
 *
 * The real backstop is that {@see UpdateWorkspaceLogo} re-encodes rather than
 * storing what arrived. Validation is what keeps the error message useful.
 */
class WorkspaceLogoRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): Response
    {
        return Gate::inspect('update', $context->workspace());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'logo' => [
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
            'logo.required' => 'Choose an image to upload.',
            'logo.image' => 'That file is not an image we can use.',
            'logo.max' => 'That image is larger than :max KB.',
            'logo.uploaded' => 'That image could not be uploaded. It may be larger than the server allows.',
        ];
    }

    public function logo(): ?UploadedFile
    {
        $file = $this->file('logo');

        return $file instanceof UploadedFile ? $file : null;
    }
}
