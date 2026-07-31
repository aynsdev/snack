<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sets or clears a workspace's logo.
 *
 * The same shape as {@see App\Actions\Profile\UpdateAvatar}, and for the same
 * reasons — read that class for the full argument. In short:
 *
 * - The logo goes on the **public** disk. It renders in the workspace rail on
 *   every page for every member, so an authorized route would be a database
 *   round trip per paint.
 * - The bytes stored are never the bytes uploaded. The image is decoded,
 *   centre-cropped square, and re-encoded, which strips EXIF, bounds what is
 *   served, and means a file that merely *claims* to be an image produces no
 *   output at all.
 * - A **fresh** path each time, never an overwrite: a new name is a new URL, so
 *   the change is immediate rather than waiting out every proxy and open tab.
 * - The previous file is deleted **last**, so there is no window in which the
 *   row points at bytes that are already gone.
 *
 * Unlike an avatar, this is workspace administration, so it leaves an audit
 * entry.
 */
class UpdateWorkspaceLogo
{
    public function __construct(
        private readonly ImageProcessor $images,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @return bool False when the upload could not be decoded as an image.
     */
    public function handle(Workspace $workspace, User $actor, UploadedFile $file): bool
    {
        $contents = @file_get_contents($file->getRealPath());

        if ($contents === false) {
            return false;
        }

        $logo = $this->images->square(
            $contents,
            (int) config('snack.workspaces.logo_dimension', 512),
        );

        if ($logo === null) {
            return false;
        }

        $disk = $this->disk();
        $previous = $workspace->logo_path;

        $path = sprintf(
            'workspace-logos/%d/%s.%s',
            $workspace->id,
            Str::ulid()->toBase32(),
            $logo->extension,
        );

        Storage::disk($disk)->put($path, $logo->contents, 'public');

        $workspace->forceFill(['logo_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk($disk)->delete($previous);
        }

        $this->record($workspace, $actor, hasLogo: true);

        return true;
    }

    public function remove(Workspace $workspace, User $actor): void
    {
        $previous = $workspace->logo_path;

        if ($previous === null) {
            return;
        }

        $workspace->forceFill(['logo_path' => null])->save();

        Storage::disk($this->disk())->delete($previous);

        $this->record($workspace, $actor, hasLogo: false);
    }

    /**
     * The entry records that the logo changed, not what it changed to — a
     * storage path is not something anyone reads a trail to learn.
     */
    private function record(Workspace $workspace, User $actor, bool $hasLogo): void
    {
        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::WorkspaceUpdated,
            actor: $actor,
            auditable: $workspace,
            newValues: ['logo' => $hasLogo ? 'set' : 'removed'],
        );
    }

    private function disk(): string
    {
        return (string) config('snack.avatars.disk', 'public');
    }
}
