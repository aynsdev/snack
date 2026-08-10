<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetFolder;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'folder_id' => null,
            'name' => Str::title(fake()->word().' '.fake()->word()),
            'description' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    /**
     * On a particular shelf.
     */
    public function inside(AssetFolder $folder): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $folder->workspace_id,
            'folder_id' => $folder->id,
        ]);
    }

    /**
     * With bytes behind it.
     *
     * The default has none, because most tests care about the library's own
     * shape — where things are filed, who may move them — and creating a second
     * row for every one of them is noise. Anything that renders an asset needs
     * this: the resource sends `file: null` without it, which is a real state
     * but not the normal one.
     *
     * The attachment is created *after* the asset, in an `afterCreating` hook,
     * because it is the attachment that carries `asset_id` — see the migration
     * that added it for why ownership points this way.
     */
    public function withFile(string $mimeType = 'image/png', ?string $originalName = null): static
    {
        return $this->afterCreating(function (Asset $asset) use ($mimeType, $originalName): void {
            MessageAttachment::factory()->create([
                'workspace_id' => $asset->workspace_id,
                'message_id' => null,
                'task_id' => null,
                'asset_id' => $asset->id,
                'uploaded_by' => $asset->created_by,
                'mime_type' => $mimeType,
                'original_name' => $originalName ?? Str::slug($asset->name).'.png',
            ]);
        });
    }
}
