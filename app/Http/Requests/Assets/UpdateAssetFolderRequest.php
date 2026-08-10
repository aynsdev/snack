<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\Concerns\ResolvesAssetFolders;
use App\Models\AssetFolder;
use App\Policies\AssetPolicy;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Renaming a folder, and moving it.
 *
 * One request for both because the dialog offers both, but the controller keeps
 * them apart: `parent_id` is only acted on when it was actually sent. See
 * {@see ResolvesAssetFolders} for why an absent key and a null one cannot be
 * allowed to mean the same thing.
 */
class UpdateAssetFolderRequest extends FormRequest
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
        return Gate::inspect('update', $this->folder());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        $workspace = $context->workspace();
        $folder = $this->folder();

        /*
         * Which siblings the new name has to be unique among: the ones it is
         * about to join if this request also moves the folder, and its current
         * ones otherwise. Checking against the old parent while moving to a new
         * one would refuse a perfectly good rename and allow a colliding one.
         */
        $parentId = $this->hasFolderKey('parent_id')
            ? ($this->input('parent_id') === null || $this->input('parent_id') === ''
                ? null
                : (int) $this->input('parent_id'))
            : $folder->parent_id;

        return [
            'name' => [
                'sometimes',
                'string',
                'min:1',
                'max:120',
                $this->uniqueAmongSiblings($workspace, $parentId, $folder->id),
            ],

            'description' => ['sometimes', 'nullable', 'string', 'max:500'],

            'parent_id' => ['sometimes', 'nullable', 'integer', $this->folderBelongsToWorkspace($workspace)],
        ];
    }

    public function folder(): AssetFolder
    {
        $folder = $this->route('assetFolder');

        abort_unless($folder instanceof AssetFolder, 404);

        return $folder;
    }

    /**
     * Whether this request is also a move.
     */
    public function movesFolder(): bool
    {
        return $this->hasFolderKey('parent_id');
    }

    public function destination(WorkspaceContext $context): ?AssetFolder
    {
        return $this->resolveFolder($context->workspace(), 'parent_id');
    }
}
