<?php

namespace App\Actions\Assets;

use App\Models\AssetFolder;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * Adds a shelf to the library.
 *
 * The parent arrives already resolved and already authorized: StoreAssetFolderRequest
 * loads it through the workspace, so a request cannot file a folder inside
 * another tenant's tree. Nothing in this namespace imports from the HTTP layer
 * to say so — an action is reachable from a command and a job too.
 */
class CreateAssetFolder
{
    public function handle(
        Workspace $workspace,
        User $creator,
        string $name,
        ?string $description = null,
        ?AssetFolder $parent = null,
    ): AssetFolder {
        $this->guardDepth($parent);

        return AssetFolder::create([
            'workspace_id' => $workspace->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'description' => $description,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Refuse a folder that would sit below the depth bound.
     *
     * Raised as a validation error rather than an abort because it is a thing
     * the person did, not a thing that went wrong: they are nested as far as the
     * library goes, and the honest answer is a message on the form rather than
     * a 403 on a dialog that then has no way to explain itself.
     */
    private function guardDepth(?AssetFolder $parent): void
    {
        if ($parent === null || $parent->depth() < AssetFolder::MAX_DEPTH) {
            return;
        }

        throw ValidationException::withMessages([
            'parent_id' => 'Folders can only be nested '.AssetFolder::MAX_DEPTH.' levels deep.',
        ]);
    }
}
