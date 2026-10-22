<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\IssueMobileToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\StoreTokenRequest;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Passport\AccessToken;

/**
 * Signing the mobile app in and out.
 *
 * The web app signs in through Fortify's session routes; a phone has no
 * session, so this trades credentials for a bearer token instead. Two-factor
 * is the same check Fortify makes, folded into one request: the first attempt
 * answers `two_factor: true`, and the app repeats it with a `code` or a
 * `recovery_code`.
 */
class TokenController extends Controller
{
    public function store(
        StoreTokenRequest $request,
        IssueMobileToken $issueToken,
        TwoFactorAuthenticationProvider $twoFactor,
    ): JsonResponse {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if ($user === null || $user->isBot() || ! Hash::check($request->string('password')->toString(), (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication() && ! $this->passesTwoFactor($user, $request, $twoFactor)) {
            return response()->json([
                'message' => $request->filled('code') || $request->filled('recovery_code')
                    ? __('The provided two factor authentication code was invalid.')
                    : __('Two factor authentication is required.'),
                'two_factor' => true,
            ], 422);
        }

        $issued = $issueToken->handle($user, $request->string('device_name')->toString());

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $issued->accessToken,
            'expires_at' => $issued->getToken()->expires_at?->toIso8601String(),
            'user' => (new AccountResource($user))->resolve($request),
        ], 201);
    }

    /**
     * Revoke the token this request was made with — signing this device out
     * and no other — and stop pushing to the phone it registered.
     */
    public function destroy(Request $request): Response
    {
        $token = $request->user()->token();

        // Always a Passport access token here — EnsureMobileToken lets nothing else in.
        if ($token instanceof AccessToken) {
            $request->user()->pushDevices()->where('oauth_access_token_id', $token->oauth_access_token_id)->delete();

            $token->revoke();
        }

        return response()->noContent();
    }

    private function passesTwoFactor(User $user, StoreTokenRequest $request, TwoFactorAuthenticationProvider $twoFactor): bool
    {
        if ($request->filled('code')) {
            return $twoFactor->verify(
                Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
                $request->string('code')->toString(),
            );
        }

        if ($request->filled('recovery_code')) {
            $recoveryCode = collect($user->recoveryCodes())
                ->first(fn (string $code): bool => hash_equals($code, $request->string('recovery_code')->toString()));

            if ($recoveryCode !== null) {
                $user->replaceRecoveryCode($recoveryCode);

                return true;
            }
        }

        return false;
    }
}
