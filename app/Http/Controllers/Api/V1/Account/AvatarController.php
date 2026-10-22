<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Actions\Profile\UpdateAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AvatarUpdateRequest;
use App\Http\Resources\Api\V1\AccountResource;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The profile picture. A multipart upload, kept apart from the profile for the
 * reason the web app keeps them apart — see Settings\AvatarController.
 */
class AvatarController extends Controller
{
    public function store(AvatarUpdateRequest $request, UpdateAvatar $avatars): AccountResource
    {
        $image = $request->avatar();

        abort_if($image === null, 422);

        if (! $avatars->handle($request->user(), $image)) {
            throw ValidationException::withMessages([
                'avatar' => 'That image could not be processed. Try a JPEG or PNG.',
            ]);
        }

        return new AccountResource($request->user());
    }

    public function destroy(Request $request, UpdateAvatar $avatars): AccountResource
    {
        $avatars->remove($request->user());

        return new AccountResource($request->user());
    }
}
