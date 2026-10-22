<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Resources\Api\V1\AccountResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProfileController extends Controller
{
    public function show(Request $request): AccountResource
    {
        return new AccountResource($request->user());
    }

    public function update(ProfileUpdateRequest $request): AccountResource
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return new AccountResource($request->user());
    }

    /**
     * Delete the account, and with it every token it holds.
     */
    public function destroy(ProfileDeleteRequest $request): Response
    {
        $user = $request->user();

        $user->tokens()->each(fn ($token) => $token->revoke());

        $user->delete();

        return response()->noContent();
    }
}
