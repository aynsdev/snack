<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\IssueMobileToken;
use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountResource;
use App\Services\RegistrationGate;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Creating an account from the mobile app.
 *
 * Registration is invitation-first, and {@see CreateNewUser} reads the
 * invitation from the session the web app's invitation page stashed it in.
 * The app has no session, so it sends the token with the form and it is put
 * where CreateNewUser looks for the length of this one request.
 *
 * Joining the workspace is a separate step — `invitations/{token}/accept` —
 * once the email address is verified, exactly as on the web.
 */
class RegistrationController extends Controller
{
    public function store(
        Request $request,
        CreateNewUser $createUser,
        RegistrationGate $gate,
        IssueMobileToken $issueToken,
    ): JsonResponse {
        $request->validate([
            'invitation_token' => ['nullable', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        if ($request->filled('invitation_token')) {
            $gate->remember($request->string('invitation_token')->toString());
        }

        try {
            $user = $createUser->create($request->only(['name', 'email', 'password', 'password_confirmation']));
        } finally {
            $gate->forget();
        }

        event(new Registered($user));

        $issued = $issueToken->handle($user, $request->string('device_name')->toString());

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $issued->accessToken,
            'expires_at' => $issued->getToken()->expires_at?->toIso8601String(),
            'user' => (new AccountResource($user))->resolve($request),
        ], 201);
    }
}
