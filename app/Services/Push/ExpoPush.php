<?php

namespace App\Services\Push;

use App\Models\PushDevice;
use App\Notifications\Messages\PushMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications through Expo's push service — the path the Snack
 * mobile app (React Native, Expo) registers for.
 *
 * Expo holds the FCM and APNs credentials (uploaded with EAS), so the server
 * needs none of its own; an access token is only required if the Expo project
 * turns on "enhanced push security". One request per device keeps the result
 * per device, which is what tells the channel which rows to prune.
 *
 * A ticket of `ok` means Expo accepted the message, not that the phone got it;
 * a token that dies later is reported in a receipt. Receipts are not polled —
 * the next send to that token comes back `DeviceNotRegistered` and prunes it.
 */
class ExpoPush
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function handles(PushDevice $device): bool
    {
        return str_starts_with($device->token, 'ExponentPushToken[')
            || str_starts_with($device->token, 'ExpoPushToken[');
    }

    public function send(PushDevice $device, PushMessage $message): PushResult
    {
        $request = Http::acceptJson()->timeout(10);

        if (filled(config('services.expo.access_token'))) {
            $request = $request->withToken((string) config('services.expo.access_token'));
        }

        try {
            $response = $request->post(self::ENDPOINT, array_filter([
                'to' => $device->token,
                'title' => $message->title,
                'body' => $message->body,
                'data' => $message->stringData() === [] ? null : $message->stringData(),
                // Snack's own sound, bundled in the app (snack-app's
                // assets/sounds/snack.wav). A build without it falls back to the
                // system sound, and to its default channel on Android.
                'sound' => PushMessage::SOUND,
                'channelId' => PushMessage::ANDROID_CHANNEL,
                'priority' => 'high',
            ], fn ($value): bool => $value !== null));
        } catch (ConnectionException $exception) {
            Log::warning('Push notification could not be sent.', ['error' => $exception->getMessage()]);

            return PushResult::Failed;
        }

        $ticket = $response->json('data');
        $ticket = is_array($ticket) && array_is_list($ticket) ? ($ticket[0] ?? null) : $ticket;

        if ($response->successful() && data_get($ticket, 'status') === 'ok') {
            return PushResult::Sent;
        }

        if (data_get($ticket, 'details.error') === 'DeviceNotRegistered') {
            return PushResult::InvalidToken;
        }

        Log::warning('Push notification was rejected by Expo.', [
            'status' => $response->status(),
            'error' => data_get($ticket, 'message') ?? $response->json('errors.0.message'),
        ]);

        return PushResult::Failed;
    }
}
