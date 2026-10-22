<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Account\StorePushDeviceRequest;
use App\Models\PushDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\AccessToken;

/**
 * Registering the phone for push notifications.
 *
 * The app calls `store` after every sign-in and whenever FCM hands it a new
 * token; it is an upsert on the token, so calling it again is harmless. A
 * token already registered to somebody else moves to whoever is signed in now
 * — the phone changed hands, and the previous owner should stop receiving
 * notifications on it.
 */
class PushDeviceController extends Controller
{
    public function store(StorePushDeviceRequest $request): JsonResponse
    {
        $token = $request->user()->token();

        $device = PushDevice::query()->updateOrCreate(
            ['token' => $request->string('token')->toString()],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->string('platform')->toString(),
                'device_name' => $request->input('device_name'),
                'oauth_access_token_id' => $token instanceof AccessToken ? $token->oauth_access_token_id : null,
                'last_registered_at' => now(),
            ],
        );

        return response()->json([
            'data' => [
                'id' => $device->id,
                'platform' => $device->platform->value,
                'device_name' => $device->device_name,
                'last_registered_at' => $device->last_registered_at->toIso8601String(),
            ],
        ], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Stop pushing to this phone — the in-app switch, or the OS permission
     * being withdrawn. Only ever the caller's own token.
     */
    public function destroy(Request $request): Response
    {
        $request->validate(['token' => ['required', 'string']]);

        $request->user()->pushDevices()->where('token', $request->string('token')->toString())->delete();

        return response()->noContent();
    }
}
