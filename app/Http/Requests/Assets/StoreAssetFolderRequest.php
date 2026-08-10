<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\Concerns\ResolvesAssetFolders;
use App\Models\AssetFolder;
use App\Policies\AssetPolicy;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreAssetFolderRequest extends FormRequest
{
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
        return Gate::inspect('create', [AssetFolder::class, $context->workspace()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        $workspace = $context->workspace();
        $parentId = $this->input('parent_id');

        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:120',
                $this->uniqueAmongSiblings(
                    $workspace,
                    $parentId === null || $parentId === '' ? null : (int) $parentId,
                ),
            ],

            'description' => ['nullable', 'string', 'max:500'],

            // Absent or null puts the folder at the top of the library.
            'parent_id' => ['nullable', 'integer', $this->folderBelongsToWorkspace($workspace)],
        ];
    }

    public function parent(WorkspaceContext $context): ?AssetFolder
    {
        return $this->resolveFolder($context->workspace(), 'parent_id');
    }
}
