<?php

namespace App\Services;

use App\Enums\MagnificModel;
use App\Exceptions\GenerationFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * The Magnific image and video generation API.
 *
 * # Three calls, no waiting
 *
 * Magnific is asynchronous: a submit returns a task id and the result arrives
 * later — seconds for most images, minutes for video. Nothing here waits.
 * {@see App\Jobs\GenerateBiteMedia} submits, then re-queues itself to check,
 * which keeps a worker free between checks and means a five-minute video costs
 * a handful of short jobs rather than one worker held for five minutes.
 *
 * It could call a webhook instead, but that needs a URL Magnific can reach,
 * which a local install does not have. Polling works everywhere.
 *
 * # Failure is an exception
 *
 * Somebody asked for a picture and has to be told why there is none, so every
 * failure becomes a {@see GenerationFailed} with a message fit to show them.
 */
class Magnific
{
    public static function isConfigured(): bool
    {
        return filled(config('services.magnific.key'));
    }

    /**
     * Start a generation and return Magnific's task id.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submit(MagnificModel $model, array $payload, bool $withImage): string
    {
        $taskId = $this->request(
            fn (): mixed => $this->client()->post($model->submitPath($withImage), $payload)->throw()->json('data.task_id'),
        );

        if (! is_string($taskId) || $taskId === '') {
            throw new GenerationFailed('The generation service did not accept the request.');
        }

        return $taskId;
    }

    /**
     * Where a task has got to.
     *
     * @return array{status: string, url: string|null}
     */
    public function check(MagnificModel $model, bool $withImage, string $taskId): array
    {
        $data = $this->request(
            fn (): mixed => $this->client()->get($model->statusPath($withImage, $taskId))->throw()->json('data'),
        );

        $url = is_array($data) ? ($data['generated'][0] ?? null) : null;

        return [
            'status' => is_array($data) ? (string) ($data['status'] ?? 'UNKNOWN') : 'UNKNOWN',
            'url' => is_string($url) && $url !== '' ? $url : null,
        ];
    }

    /**
     * Fetch a finished result.
     *
     * @return array{bytes: string, mime_type: string}
     */
    public function download(string $url): array
    {
        $response = $this->request(fn () => Http::timeout(120)->get($url)->throw());

        return [
            'bytes' => $response->body(),
            'mime_type' => str($response->header('Content-Type') ?: 'application/octet-stream')->before(';')->trim()->lower()->toString(),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function request(callable $call): mixed
    {
        try {
            return $call();
        } catch (RequestException $exception) {
            report($exception);

            $message = $exception->response->json('message') ?? $exception->response->json('detail');

            throw new GenerationFailed(
                'The generation service refused the request'.(is_string($message) ? ": {$message}" : '.'),
                previous: $exception,
            );
        } catch (ConnectionException $exception) {
            report($exception);

            throw new GenerationFailed('The generation service could not be reached.', previous: $exception);
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.magnific.url'))
            ->withHeaders(['x-magnific-api-key' => (string) config('services.magnific.key')])
            ->acceptJson()
            ->timeout(30);
    }
}
