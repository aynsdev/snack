<?php

namespace App\Services\Push;

use App\Enums\PushPlatform;
use App\Models\PushDevice;
use App\Notifications\Messages\PushMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends push notifications through the FCM HTTP v1 API.
 *
 * # Authentication
 *
 * FCM v1 takes a short-lived OAuth access token, minted by signing a JWT with
 * the service account's private key and trading it at Google's token endpoint.
 * That is a dozen lines of `openssl_sign`, so it is done here rather than by
 * adding Google's SDK for one request. The access token lives for an hour and
 * is cached for a little less, so a burst of notifications costs one exchange.
 *
 * # Failures
 *
 * Push is best-effort, like the email it sits beside. A failed send is logged
 * and reported as such; it never throws into the notification pipeline, where
 * it would fail the job and retry the database row and broadcast with it.
 */
class FirebaseMessaging
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE_KEY = 'snack:fcm:access-token';

    public function isConfigured(): bool
    {
        return filled(config('services.fcm.project_id')) && $this->credentials() !== null;
    }

    public function send(PushDevice $device, PushMessage $message): PushResult
    {
        $projectId = (string) config('services.fcm.project_id');

        try {
            $response = Http::withToken($this->accessToken())
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => $this->payload($device, $message),
                ]);
        } catch (ConnectionException|RuntimeException $exception) {
            Log::warning('Push notification could not be sent.', ['error' => $exception->getMessage()]);

            return PushResult::Failed;
        }

        if ($response->successful()) {
            return PushResult::Sent;
        }

        if ($this->isInvalidToken($response->status(), (array) $response->json('error.details', []))) {
            return PushResult::InvalidToken;
        }

        Log::warning('Push notification was rejected by FCM.', [
            'status' => $response->status(),
            'error' => $response->json('error.message'),
        ]);

        return PushResult::Failed;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PushDevice $device, PushMessage $message): array
    {
        $payload = [
            'token' => $device->token,
            'notification' => [
                'title' => $message->title,
                'body' => $message->body,
            ],
        ];

        // FCM wants an object here, and an empty array would encode as `[]`.
        if ($message->stringData() !== []) {
            $payload['data'] = $message->stringData();
        }

        if ($device->platform === PushPlatform::Android) {
            $payload['android'] = [
                'priority' => 'high',
                'notification' => array_filter([
                    'sound' => 'default',
                    'tag' => $message->thread,
                ]),
            ];
        }

        if ($device->platform === PushPlatform::Ios) {
            $payload['apns'] = [
                'payload' => [
                    'aps' => array_filter([
                        'sound' => 'default',
                        'thread-id' => $message->thread,
                    ]),
                ],
            ];
        }

        return $payload;
    }

    /**
     * FCM answers 404 `UNREGISTERED` for an uninstalled app, and 400
     * `INVALID_ARGUMENT` for a token that was never valid.
     *
     * @param  array<int, mixed>  $details
     */
    private function isInvalidToken(int $status, array $details): bool
    {
        $codes = collect($details)->pluck('errorCode')->filter()->all();

        return $status === 404
            || in_array('UNREGISTERED', $codes, true)
            || ($status === 400 && in_array('INVALID_ARGUMENT', $codes, true));
    }

    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(50), function (): string {
            $credentials = $this->credentials() ?? throw new RuntimeException('FCM credentials are not configured.');
            $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

            $response = Http::asForm()->timeout(10)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion($credentials, $tokenUri),
            ]);

            $token = $response->json('access_token');

            if (! $response->successful() || ! is_string($token)) {
                throw new RuntimeException('Google refused the FCM service account: '.$response->json('error_description', $response->status()));
            }

            return $token;
        });
    }

    /**
     * The signed JWT Google trades for an access token.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function assertion(array $credentials, string $tokenUri): string
    {
        $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => now()->getTimestamp(),
            'exp' => now()->addHour()->getTimestamp(),
        ]);

        if (! openssl_sign($unsigned, $signature, (string) $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The FCM service account key could not sign a token.');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * The service account, from a file path or inline JSON.
     *
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        $configured = config('services.fcm.credentials');

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        $json = str_starts_with(ltrim($configured), '{')
            ? $configured
            : (is_readable($configured) ? (string) file_get_contents($configured) : '');

        $credentials = json_decode($json, true);

        return is_array($credentials) && isset($credentials['client_email'], $credentials['private_key'])
            ? $credentials
            : null;
    }
}
