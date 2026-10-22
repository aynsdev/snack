<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMobileToken;
use App\Services\LiveKit\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Features;

/**
 * What the mobile app needs to know about this installation before anyone
 * signs in: what it is called, which sign-in features are on, and where the
 * realtime socket lives.
 *
 * Channel subscriptions are authorized at `POST /api/v1/broadcasting/auth`
 * with the bearer token, so Echo on the device is configured with that URL and
 * an `Authorization` header instead of the web app's cookie.
 */
class ClientConfigController extends Controller
{
    public function __invoke(TokenIssuer $liveKit): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
                'token_scope' => EnsureMobileToken::SCOPE,
                'features' => [
                    'registration' => (bool) config('snack.allow_public_registration'),
                    'password_reset' => Features::enabled(Features::resetPasswords()),
                    'two_factor' => Features::enabled(Features::twoFactorAuthentication()),
                    'huddles' => $liveKit->isConfigured(),
                    // Expo push needs no server credentials, so it is always on.
                    'push_notifications' => true,
                ],
                'broadcasting' => [
                    'driver' => 'reverb',
                    'auth_endpoint' => url('/api/v1/broadcasting/auth'),
                    ...config('snack.mobile.reverb'),
                ],
            ],
        ]);
    }
}
