<?php

namespace App\Services\Outbound;

use App\Enums\OutboundEventStatus;
use App\Jobs\DeliverOutboundEvent;
use App\Models\OutboundEvent;
use App\Services\WebhookSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one outbox row to the agent dispatcher.
 *
 * # Claim, then send, then record
 *
 * A row is claimed in a short transaction — `FOR UPDATE SKIP LOCKED`, then its
 * `available_at` pushed out by a lease — and the HTTP call happens after that
 * transaction has committed. Holding the row lock across a ten-second request
 * would pin a database connection and a transaction for as long as the
 * receiver cares to take; the lease gives the same guarantee without it. Two
 * workers handed the same id cannot both send it: the second finds the row
 * either locked or not yet due again, and walks away.
 *
 * If a worker dies mid-send the lease simply runs out and the sweeper sends the
 * row again. That is why delivery is at-least-once and the receiver dedupes on
 * the signed `event_id`.
 *
 * # Signing
 *
 * Exactly the incoming-webhook scheme — {@see WebhookSignature::sign()} — with
 * a fresh timestamp on every attempt, so a retry an hour later is not refused
 * as a replay. The body is identical on every attempt.
 */
class OutboundDelivery
{
    public const TIMEOUT_SECONDS = 10;

    /**
     * Short, because a dispatcher that cannot even accept a connection is down,
     * and every second spent finding that out is a worker not doing anything.
     */
    public const CONNECT_TIMEOUT_SECONDS = 3;

    /**
     * How long a claimed row is left alone. Comfortably longer than the request
     * can take, so a slow receiver cannot have the same event in flight twice.
     */
    public const LEASE_SECONDS = 120;

    /**
     * How long an event keeps being retried before it is given up as dead.
     */
    public const RETRY_WINDOW_HOURS = 24;

    /**
     * Seconds to wait after each failed attempt; the last applies from then on.
     *
     * @var array<int, int>
     */
    public const BACKOFF_SECONDS = [10, 30, 120, 600, 1800, 3600];

    public const USER_AGENT = 'Snack-Outbound/1';

    public const EVENT_HEADER = 'X-Snack-Event';

    public function __construct(
        private readonly OutboundEvents $events,
        private readonly WebhookSignature $signatures,
    ) {}

    /**
     * @return bool whether the receiver accepted the event on this attempt
     */
    public function attempt(int $eventId): bool
    {
        if (! $this->events->enabled()) {
            return false;
        }

        $event = $this->claim($eventId);

        if ($event === null) {
            return false;
        }

        [$status, $error, $permanent] = $this->send($event);

        if ($error === null) {
            $this->delivered($event, $status);

            return true;
        }

        $this->failed($event, $status, $error, $permanent);

        return false;
    }

    /**
     * Lock the row if it is due and nobody else holds it, and lease it.
     */
    private function claim(int $eventId): ?OutboundEvent
    {
        return DB::transaction(function () use ($eventId): ?OutboundEvent {
            $event = OutboundEvent::query()
                ->whereKey($eventId)
                ->due()
                ->lock('for update skip locked')
                ->first();

            if ($event === null) {
                return null;
            }

            /*
             * Past its window it is not sent at all, even once. After a long
             * outage the dispatcher has moved on — a day-old assignment arriving
             * now would start work nobody is waiting for any more.
             */
            if ($this->expired($event)) {
                $event->forceFill([
                    'status' => OutboundEventStatus::Dead,
                    'last_error' => 'Expired before it could be delivered.',
                ])->save();

                $this->reportDead($event);

                return null;
            }

            $event->forceFill([
                'attempts' => $event->attempts + 1,
                'available_at' => now()->addSeconds(self::LEASE_SECONDS),
            ])->save();

            return $event;
        });
    }

    /**
     * @return array{0: int|null, 1: string|null, 2: bool} the status, why it failed, and whether retrying could ever help
     */
    private function send(OutboundEvent $event): array
    {
        /*
         * The body is the same on every attempt, so one that cannot be built
         * now never will be: given up at once rather than retried for a day.
         */
        try {
            $body = json_encode($event->envelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            return [null, $exception::class.' encoding the event', true];
        }

        $timestamp = (string) now()->getTimestamp();

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                // A redirect is a misconfigured endpoint, not a delivery.
                ->withoutRedirecting()
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders([
                    WebhookSignature::TIMESTAMP_HEADER => $timestamp,
                    WebhookSignature::SIGNATURE_HEADER => $this->signatures->sign(
                        (string) config('snack.agents.dispatcher_secret'),
                        $timestamp,
                        $body,
                    ),
                    self::EVENT_HEADER => $event->type->value,
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('snack.agents.dispatcher_url'));
        } catch (Throwable $exception) {
            return [null, $exception::class.' contacting '.$this->host(), false];
        }

        if ($response->successful()) {
            return [$response->status(), null, false];
        }

        return [$response->status(), "HTTP {$response->status()} from {$this->host()}", false];
    }

    private function delivered(OutboundEvent $event, ?int $status): void
    {
        $this->release($event, [
            'status' => OutboundEventStatus::Delivered,
            'delivered_at' => now(),
            'last_status' => $status,
            'last_error' => null,
        ]);
    }

    private function failed(OutboundEvent $event, ?int $status, string $error, bool $permanent): void
    {
        $dead = $permanent || $this->expired($event);

        $delay = self::backoff($event->attempts);

        $released = $this->release($event, [
            'status' => $dead ? OutboundEventStatus::Dead : OutboundEventStatus::Pending,
            'available_at' => now()->addSeconds($delay),
            'last_status' => $status,
            'last_error' => $error,
        ]);

        if ($dead) {
            if ($released) {
                $this->reportDead($event->forceFill(['last_error' => $error]));
            }

            return;
        }

        DeliverOutboundEvent::dispatch($event->id)->delay(now()->addSeconds($delay));

        OutboundEvent::query()->whereKey($event->id)->update(['dispatched_at' => now()]);
    }

    /**
     * Said out loud, because a dead event is an assignment, a cancellation or
     * a reply the dispatcher will never hear about, and nothing else notices.
     */
    private function reportDead(OutboundEvent $event): void
    {
        Log::warning('An agent dispatcher event was given up on and will not be delivered.', [
            'outbound_event_id' => $event->id,
            'event_id' => $event->event_id,
            // Raw, since an event that could not be encoded may not cast either.
            'type' => $event->getRawOriginal('type'),
            'workspace_id' => $event->workspace_id,
            'sequence' => $event->sequence,
            'attempts' => $event->attempts,
            'last_error' => $event->last_error,
        ]);
    }

    /**
     * End the lease with the attempt's outcome — but only if the lease is still
     * ours. A worker that outlived its lease finds `attempts` moved on by the
     * one that took over, and leaves that worker's record alone.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool whether the lease was still ours
     */
    private function release(OutboundEvent $event, array $attributes): bool
    {
        return OutboundEvent::query()
            ->whereKey($event->id)
            ->where('attempts', $event->attempts)
            ->update($attributes) > 0;
    }

    private function expired(OutboundEvent $event): bool
    {
        return $event->created_at === null
            || $event->created_at->copy()->addHours(self::RETRY_WINDOW_HOURS)->lessThanOrEqualTo(now());
    }

    /**
     * What `last_error` names the receiver by. The host alone: the configured
     * URL may carry credentials, and what the receiver said back is its own
     * business — neither belongs in a table admins can read.
     */
    private function host(): string
    {
        return (string) (parse_url((string) config('snack.agents.dispatcher_url'), PHP_URL_HOST) ?: 'the dispatcher');
    }

    /**
     * Seconds to wait after the given attempt failed.
     */
    public static function backoff(int $attempts): int
    {
        $index = min(max($attempts, 1), count(self::BACKOFF_SECONDS)) - 1;

        return self::BACKOFF_SECONDS[$index];
    }
}
