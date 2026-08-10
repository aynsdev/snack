<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\Concerns\ResolvesAssetFolders;
use App\Models\Asset;
use App\Models\AssetFolder;
use App\Policies\AssetPolicy;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Re-labelling an asset, and moving it between shelves.
 *
 * `folder_id` is only acted on when it was actually sent — a field left out of a
 * rename must not be read as "and move this to the top of the library". See
 * {@see ResolvesAssetFolders}.
 */
class UpdateAssetRequest extends FormRequest
{
    use ResolvesAssetFolders;

    /**
     * The policy's own response, not a boolean.
     *
     * {@see AssetPolicy} answers not-found rather than forbidden
     * for a guest, and `can()` would flatten that to a 403 — which is itself the
     * confirmation the policy is refusing to give.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->asset());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'folder_id' => [
                'sometimes',
                'nullable',
                'integer',
                $this->folderBelongsToWorkspace($context->workspace()),
            ],
        ];
    }

    public function asset(): Asset
    {
        $asset = $this->route('asset');

        abort_unless($asset instanceof Asset, 404);

        return $asset;
    }

    public function movesAsset(): bool
    {
        return $this->hasFolderKey('folder_id');
    }

    public function destination(WorkspaceContext $context): ?AssetFolder
    {
        return $this->resolveFolder($context->workspace(), 'folder_id');
    }
}
