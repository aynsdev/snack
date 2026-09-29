<?php

namespace App\Mcp\Tools;

use App\Models\Asset;
use App\Models\AssetFolder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Walking the asset library's shelves: the folders at one level and the files
 * on them, the way the library screen shows them.
 *
 * `find_files` is for "where is the thing called X"; this is for "what is in
 * Brand", which a name search cannot answer. Guests are refused, as
 * AssetPolicy refuses them the library — it is where the contracts and the
 * price list live.
 */
#[Name('browse_assets')]
#[Title('Browse Assets')]
#[Description(<<<'TEXT'
Browse the workspace's asset library — its logos, brand files, templates, and
company documents — one folder at a time. With no folder, lists the top level.
Pass a folder id or name from a previous call to open it.

Each file is listed with a file id that `read_file` takes. To search the
library by name instead, use `find_files`.
TEXT)]
#[IsReadOnly]
class BrowseAssetsTool extends SnackTool
{
    public function handle(Request $request): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        if (Gate::forUser($context->user)->denies('viewAny', [Asset::class, $context->workspace])) {
            return Response::error('This person cannot see the asset library.');
        }

        $named = $request->get('folder');
        $folder = null;

        if ($named !== null && $named !== '') {
            $folders = AssetFolder::query()->where('workspace_id', $context->workspace->id);

            if (is_numeric($named)) {
                $folder = $folders->find((int) $named);
            } else {
                // By name only when it is unambiguous: two shelves called
                // "Archive" in different places are two different answers.
                $matches = $folders->where('name', 'ilike', trim((string) $named))->limit(2)->get();
                $folder = $matches->count() === 1 ? $matches->first() : null;
            }

            if ($folder === null) {
                return Response::error("There is no folder [{$named}] in the asset library. Call this tool with no folder to see the top level.");
            }
        }

        $children = AssetFolder::query()
            ->where('workspace_id', $context->workspace->id)
            ->where('parent_id', $folder?->id)
            ->withCount(['assets', 'children'])
            ->orderBy('name')
            ->get()
            ->map(fn (AssetFolder $child): string => "- folder #{$child->id} {$child->name} ({$child->children_count} folder(s), {$child->assets_count} file(s))"
                .($child->description ? " — {$child->description}" : ''))
            ->implode("\n");

        $assets = Asset::query()
            ->where('workspace_id', $context->workspace->id)
            ->where('folder_id', $folder?->id)
            ->whereHas('file')
            ->with('file')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn (Asset $asset): string => sprintf(
                '- file #%d %s (%s, %s)%s',
                $asset->file->id,
                $asset->name,
                $asset->file->original_name,
                $asset->file->humanSize(),
                $asset->description ? " — {$asset->description}" : '',
            ))
            ->implode("\n");

        $where = $folder === null
            ? 'Asset library (top level)'
            : 'Asset library / '.$folder->ancestors()->pluck('name')->implode(' / ');

        return Response::text(implode("\n\n", array_filter([
            $where,
            $children !== '' ? "Folders:\n{$children}" : null,
            $assets !== '' ? "Files:\n{$assets}" : null,
            $children === '' && $assets === '' ? '(empty)' : null,
        ])));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()
                ->description('Optional: the folder id (a number) or exact name to open. Omit for the top level.'),
        ];
    }
}
