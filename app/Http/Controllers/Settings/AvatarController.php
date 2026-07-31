<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Profile\UpdateAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AvatarUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

/**
 * The profile picture.
 *
 * Split out from {@see ProfileController} rather than folded into the profile
 * form, because the two are submitted differently: a file needs a multipart
 * request, and merging them would turn every name change into one as well.
 * Separate endpoints also mean uploading a picture does not re-validate — or
 * silently re-save — the email address.
 *
 * Both actions redirect back rather than answering with JSON: the new URL has to
 * reach the sidebar, the header, and every message the person has written on the
 * page, all of which read `auth.user` from the shared Inertia props. A redirect
 * refreshes those in one round trip.
 */
class AvatarController extends Controller
{
    public function store(AvatarUpdateRequest $request, UpdateAvatar $avatars): RedirectResponse
    {
        $image = $request->avatar();

        abort_if($image === null, 422);

        if (! $avatars->handle($request->user(), $image)) {
            /*
             * Decoding failed despite passing validation — a truncated file, or
             * a format this build of GD cannot read. Reported on the field so it
             * lands next to the control the person just used.
             */
            return Redirect::back()->withErrors([
                'avatar' => 'That image could not be processed. Try a JPEG or PNG.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture updated.')]);

        return to_route('profile.edit');
    }

    public function destroy(Request $request, UpdateAvatar $avatars): RedirectResponse
    {
        $avatars->remove($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture removed.')]);

        return to_route('profile.edit');
    }
}
