<?php

namespace App\Actions\Profile;

use App\Models\User;
use App\Services\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sets or clears a person's profile picture.
 *
 * # Why an avatar is public and an attachment is not
 *
 * Every other upload in Snack lands on a private disk behind a signed,
 * per-viewer authorized route. An avatar cannot: it renders next to every
 * message its owner has ever written, for everyone who can read that message, so
 * an authorized route would mean a database round trip per face on screen. It
 * goes on the public disk instead — which is a deliberate decision about a photo
 * the person chose to represent themselves with, not an oversight.
 *
 * # The bytes stored are never the bytes uploaded
 *
 * The image is decoded, centre-cropped square, and re-encoded by
 * {@see ImageProcessor}. That does three things at once: it strips EXIF, which
 * on a phone photo carries the GPS coordinates of where it was taken; it bounds
 * what is served, so nobody's avatar is a 12 MB original; and it means a file
 * that merely *claims* to be an image cannot survive the round trip, because
 * something that will not decode produces no output at all.
 *
 * # The old file goes last
 *
 * The new avatar is written and the column updated before the previous file is
 * deleted. The other order has a window in which the row points at bytes that
 * are already gone, and every avatar in the app renders as a broken image for
 * the length of it.
 */
class UpdateAvatar
{
    public function __construct(private readonly ImageProcessor $images) {}

    /**
     * @return bool False when the upload could not be decoded as an image.
     */
    public function handle(User $user, UploadedFile $file): bool
    {
        $contents = @file_get_contents($file->getRealPath());

        if ($contents === false) {
            return false;
        }

        $avatar = $this->images->square(
            $contents,
            (int) config('snack.avatars.dimension', 512),
        );

        if ($avatar === null) {
            return false;
        }

        $disk = (string) config('snack.avatars.disk', 'public');
        $previous = $user->avatar_path;

        /*
         * A fresh ULID every time rather than a stable per-user key. Overwriting
         * one path would leave every browser, proxy, and open tab showing the old
         * picture until its cache expired — which for an avatar people change
         * precisely because they want to be seen differently is the whole point
         * missed. A new name is a new URL, so the change is immediate.
         */
        $path = sprintf('avatars/%d/%s.%s', $user->id, Str::ulid()->toBase32(), $avatar->extension);

        Storage::disk($disk)->put($path, $avatar->contents, 'public');

        $user->forceFill(['avatar_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk($disk)->delete($previous);
        }

        return true;
    }

    public function remove(User $user): void
    {
        $previous = $user->avatar_path;

        if ($previous === null) {
            return;
        }

        $user->forceFill(['avatar_path' => null])->save();

        Storage::disk((string) config('snack.avatars.disk', 'public'))->delete($previous);
    }
}
