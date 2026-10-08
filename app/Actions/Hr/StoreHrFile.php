<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Puts a contract scan, record attachment, or other HR file on the private
 * uploads disk.
 *
 * Never the public disk: these are signed contracts and disciplinary letters,
 * and they are only ever served back through a policy-checked download route.
 */
class StoreHrFile
{
    /**
     * @return array{file_path: string, file_name: string}
     */
    public function handle(Employee $employee, UploadedFile $file, string $folder): array
    {
        $path = $file->store(
            "hr/{$employee->workspace_id}/{$employee->id}/{$folder}",
            ['disk' => self::disk()],
        );

        return [
            'file_path' => (string) $path,
            'file_name' => str($file->getClientOriginalName())->limit(200, '')->toString(),
        ];
    }

    /**
     * A file that belongs to the workspace rather than to one person — a
     * company policy's attachment.
     *
     * @return array{file_path: string, file_name: string}
     */
    public function forWorkspace(Workspace $workspace, UploadedFile $file, string $folder): array
    {
        $path = $file->store("hr/{$workspace->id}/{$folder}", ['disk' => self::disk()]);

        return [
            'file_path' => (string) $path,
            'file_name' => str($file->getClientOriginalName())->limit(200, '')->toString(),
        ];
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::disk())->delete($path);
        }
    }

    public static function disk(): string
    {
        return (string) config('snack.uploads.disk', 'local');
    }
}
