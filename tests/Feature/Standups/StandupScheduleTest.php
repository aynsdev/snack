<?php

use App\Models\Standup;
use Carbon\CarbonImmutable;

/**
 * When a standup is due.
 *
 * The rule these all circle is that "due" is not "the clock reads 09:30". The
 * scheduler misses minutes — worker restarts, deploys, a sleeping laptop — so
 * the question is whether there is an unhonoured ask time in the recent past,
 * bounded so a catch-up cannot become an ambush.
 */
it('is due at its scheduled minute', function () {
    $standup = Standup::factory()->daily()->at('09:30')->make();

    expect($standup->dueDate(CarbonImmutable::parse('2026-08-10 09:30:00', 'UTC')))
        ->not->toBeNull();
});

it('is still due a few minutes late, because the scheduler misses minutes', function () {
    $standup = Standup::factory()->daily()->at('09:30')->make();

    $date = $standup->dueDate(CarbonImmutable::parse('2026-08-10 09:59:00', 'UTC'));

    expect($date)->not->toBeNull()
        ->and($date->toDateString())->toBe('2026-08-10');
});

it('stops being due once the catch-up window closes', function () {
    $standup = Standup::factory()->daily()->at('09:30')->make();

    // Four hours later. Without the bound, a deployment that was down all
    // morning would come back up and ask about a standup whose day is over.
    expect($standup->dueDate(CarbonImmutable::parse('2026-08-10 13:30:00', 'UTC')))
        ->toBeNull();
});

it('is not due before its time', function () {
    $standup = Standup::factory()->daily()->at('09:30')->make();

    expect($standup->dueDate(CarbonImmutable::parse('2026-08-10 08:00:00', 'UTC')))
        ->toBeNull();
});

it('reads its time in its own timezone, not the server clock', function () {
    // 09:30 in Manila is 01:30 UTC. A standup that fired on the server's clock
    // would ask this team at half past five in the evening.
    $standup = Standup::factory()->daily()->at('09:30', 'Asia/Manila')->make();

    expect($standup->dueDate(CarbonImmutable::parse('2026-08-10 01:30:00', 'UTC')))
        ->not->toBeNull()
        ->and($standup->dueDate(CarbonImmutable::parse('2026-08-10 09:30:00', 'UTC')))
        ->toBeNull();
});

it('dates the run by the local day, not the UTC one', function () {
    // 09:30 in Manila on the 11th is 01:30 UTC on the 11th; at 23:00 UTC on the
    // 10th it is already the 11th there. The run has to be dated the way the
    // team would name the day, or the summary is titled with yesterday.
    $standup = Standup::factory()->daily()->at('07:00', 'Asia/Manila')->make();

    $date = $standup->dueDate(CarbonImmutable::parse('2026-08-10 23:05:00', 'UTC'));

    expect($date?->toDateString())->toBe('2026-08-11');
});

it('only runs on the days it was given', function () {
    // 2026-08-08 is a Saturday.
    $weekdays = Standup::factory()->at('09:30')->make();

    expect($weekdays->dueDate(CarbonImmutable::parse('2026-08-08 09:30:00', 'UTC')))->toBeNull()
        ->and($weekdays->dueDate(CarbonImmutable::parse('2026-08-10 09:30:00', 'UTC')))->not->toBeNull();
});

it('honours yesterday inside the window when the clock has passed midnight', function () {
    // Asked at 23:50, looked at ten minutes later — which is the next day.
    $standup = Standup::factory()->daily()->at('23:50')->make();

    $date = $standup->dueDate(CarbonImmutable::parse('2026-08-11 00:05:00', 'UTC'));

    expect($date?->toDateString())->toBe('2026-08-10');
});

it('is never due while paused', function () {
    $standup = Standup::factory()->daily()->at('09:30')->paused()->make();

    expect($standup->dueDate(CarbonImmutable::parse('2026-08-10 09:30:00', 'UTC')))
        ->toBeNull();
});

it('is never due with no questions to ask', function () {
    $standup = Standup::factory()->daily()->at('09:30')->make(['questions' => []]);

    expect($standup->dueDate(CarbonImmutable::parse('2026-08-10 09:30:00', 'UTC')))
        ->toBeNull();
});

it('describes its schedule in one line', function () {
    expect(Standup::factory()->at('09:30', 'Europe/London')->make()->scheduleSummary())
        ->toBe('Weekdays at 09:30 (Europe/London)')
        ->and(Standup::factory()->daily()->at('08:00')->make()->scheduleSummary())
        ->toBe('Every day at 08:00 (UTC)')
        ->and(Standup::factory()->at('08:00')->make(['days_of_week' => [1, 4]])->scheduleSummary())
        ->toBe('Monday and Thursday at 08:00 (UTC)');
});
