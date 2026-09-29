<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Puts a contract scan or record attachment on the private uploads disk.
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
