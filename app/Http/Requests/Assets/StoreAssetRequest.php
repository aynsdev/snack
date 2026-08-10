<?php

namespace App\Http\Requests\Assets;

use App\Actions\Assets\StoreAsset;
use App\Http\Requests\Concerns\RefusesExecutableUploads;
use App\Http\Requests\Concerns\ResolvesAssetFolders;
use App\Http\Requests\Uploads\StoreUploadRequest;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Policies\AssetPolicy;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * A file on its way onto a shelf.
 *
 * Unlike the composer's tray this arrives complete: the bytes, the shelf, and
 * the name it will be known by, in one request. There is no claim step to get
 * wrong because there is nothing to claim later — see
 * {@see StoreAsset}.
 */
class StoreAssetRequest extends FormRequest
{
    use RefusesExecutableUploads;
    use ResolvesAssetFolders;

    /**
     * The policy's own response, not a boolean.
     *
     * {@see AssetPolicy} answers not-found rather than forbidden
     * for a guest, and `can()` would flatten that to a 403 — which is itself the
     * confirmation the policy is refusing to give.
     */
    public function authorize(WorkspaceContext $context): Response
    {
        return Gate::inspect('create', [Asset::class, $context->workspace()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.config('snack.uploads.max_size_kb', 51_200),
                $this->notExecutable(),
            ],

            // Blank means "call it whatever the file was called", which is what
            // most uploads want and none of them should have to type.
            'name' => ['nullable', 'string', 'max:200'],

            'description' => ['nullable', 'string', 'max:2000'],

            'folder_id' => [
                'nullable',
                'integer',
                $this->folderBelongsToWorkspace($context->workspace()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to add to the library.',
            'file.max' => 'That file is larger than :max KB.',
            'file.uploaded' => 'That file could not be uploaded. It may be larger than the server allows.',
        ];
    }

    /**
     * The validated upload.
     *
     * Named `upload()` rather than `file()` for the reason
     * {@see StoreUploadRequest::upload()} is:
     * `Request::file()` already exists with a different signature, and
     * overriding it breaks every caller expecting the framework's.
     */
    public function upload(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function folder(WorkspaceContext $context): ?AssetFolder
    {
        return $this->resolveFolder($context->workspace(), 'folder_id');
    }
}
