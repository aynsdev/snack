<?php

use App\Enums\MemberStatus;
use App\Enums\MessageType;
use App\Enums\StandupResponseState;
use App\Enums\StandupRunState;
use App\Enums\WorkspaceRole;
use App\Models\Message;
use App\Models\Standup;
use App\Models\StandupResponse;
use App\Models\StandupRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/**
 * The bot: who it asks, what it sends them, and what it posts back.
 *
 * Everything here goes through the command rather than the actions, because the
 * thing worth protecting is the whole path — a standup that is due, direct
 * messages that arrive, and a summary in the channel. The actions are exercised
 * directly only where a state cannot be reached from the schedule.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->bo = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    addToChannel($this->channel, $this->ada);
    addToChannel($this->channel, $this->bo);

    $this->standup = Standup::factory()
        ->forChannel($this->channel)
        ->daily()
        ->at('09:30')
        ->create(['questions' => ['What are you working on?', 'Anything blocking you?']]);
});

/**
 * Wind the clock to the standup's minute and run the scheduler.
 */
function runStandupsAt(string $time): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'UTC'));

    try {
        Artisan::call('snack:run-standups');
    } finally {
        CarbonImmutable::setTestNow();
    }
}

it('direct-messages every member of the channel when the standup falls due', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $run = StandupRun::query()->firstOrFail();

    expect($run->standup_id)->toBe($this->standup->id)
        ->and($run->scheduled_for->toDateString())->toBe('2026-08-10')
        ->and($run->asked_at)->not->toBeNull()
        ->and($run->responses()->count())->toBe(2);

    foreach ([$this->ada, $this->bo] as $person) {
        $response = StandupResponse::query()
            ->where('standup_run_id', $run->id)
            ->where('user_id', $person->id)
            ->firstOrFail();

        expect($response->state)->toBe(StandupResponseState::Pending)
            ->and($response->prompt_message_id)->not->toBeNull();

        $prompt = Message::query()->findOrFail($response->prompt_message_id);

        // A direct message, not a channel post: the questions are asked in
        // private and only the answers are published.
        expect($prompt->conversation_id)->not->toBeNull()
            ->and($prompt->channel_id)->toBeNull()
            ->and($prompt->body)->toContain('What are you working on?')
            ->and($prompt->body)->toContain('Anything blocking you?');
    }
});

it('does not ask people who are not in the channel', function () {
    $outsider = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    runStandupsAt('2026-08-10 09:30:00');

    expect(StandupResponse::query()->where('user_id', $outsider->id)->exists())->toBeFalse();
});

it('asks only the selected people when the standup is narrowed', function () {
    $this->standup->update(['participant_ids' => [$this->ada->id]]);

    runStandupsAt('2026-08-10 09:30:00');

    expect(StandupResponse::query()->where('user_id', $this->ada->id)->exists())->toBeTrue()
        ->and(StandupResponse::query()->where('user_id', $this->bo->id)->exists())->toBeFalse();
});

it('does not ask a selected person who has left the channel', function () {
    $outsider = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->standup->update(['participant_ids' => [$this->ada->id, $outsider->id]]);

    runStandupsAt('2026-08-10 09:30:00');

    expect(StandupResponse::query()->pluck('user_id')->all())->toBe([$this->ada->id]);
});

it('does not ask a suspended member', function () {
    $suspended = joinWorkspace(
        $this->workspace,
        null,
        WorkspaceRole::Member,
        MemberStatus::Suspended,
    );
    addToChannel($this->channel, $suspended);

    runStandupsAt('2026-08-10 09:30:00');

    expect(StandupResponse::query()->where('user_id', $suspended->id)->exists())->toBeFalse();
});

it('does not ask the bot itself', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $bot = User::query()->where('is_bot', true)->firstOrFail();

    // It is in the channel — it posts the summary there — but it is not a
    // person, so it is not on the roster.
    expect($this->channel->fresh()->hasMember($bot))->toBeTrue()
        ->and(StandupResponse::query()->where('user_id', $bot->id)->exists())->toBeFalse();
});

it('asks once, however many times the scheduler wakes', function () {
    runStandupsAt('2026-08-10 09:30:00');
    runStandupsAt('2026-08-10 09:31:00');
    runStandupsAt('2026-08-10 09:32:00');

    expect(StandupRun::query()->count())->toBe(1)
        ->and(StandupResponse::query()->count())->toBe(2)
        // Two prompts, one per person — not six.
        ->and(Message::query()->whereNotNull('conversation_id')->count())->toBe(2);
});

it('resumes an ask that died halfway through', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $run = StandupRun::query()->firstOrFail();

    // Simulate a worker that died after asking Ada: drop Bo's row and clear the
    // stamp, which is the state a half-finished fan-out leaves behind.
    StandupResponse::query()->where('user_id', $this->bo->id)->delete();
    $run->forceFill(['asked_at' => null])->save();

    $before = Message::query()->whereNotNull('conversation_id')->count();

    runStandupsAt('2026-08-10 09:35:00');

    expect(StandupResponse::query()->where('standup_run_id', $run->id)->count())->toBe(2)
        // Only Bo is asked again. Ada, who already had a row, is not asked twice.
        ->and(Message::query()->whereNotNull('conversation_id')->count())->toBe($before + 1)
        ->and($run->fresh()->asked_at)->not->toBeNull();
});

it('posts an answer to the channel the moment it is given', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $run = StandupRun::query()->firstOrFail();

    $response = StandupResponse::query()
        ->where('standup_run_id', $run->id)
        ->where('user_id', $this->ada->id)
        ->firstOrFail();

    $this->actingAs($this->ada)
        ->postJson("/w/{$this->workspace->slug}/standup-responses/{$response->id}", [
            'answers' => ['Shipping the parser', 'Review on #418'],
        ])
        ->assertOk();

    // Still open — one of two people. The channel hears from Ada anyway, which
    // is the whole point: an answer is worth reading at 09:31, not at lunchtime.
    expect($run->fresh()->isOpen())->toBeTrue();

    $posted = Message::query()->findOrFail($response->fresh()->answer_message_id);

    expect($posted->channel_id)->toBe($this->channel->id)
        ->and($posted->body)->toContain($this->ada->name)
        ->and($posted->body)->toContain('Shipping the parser')
        ->and($posted->body)->toContain('Review on #418')
        // A real message from a real sender, so it renders, paginates and
        // searches like anything else in the channel.
        ->and($posted->type)->toBe(MessageType::Text)
        ->and($posted->sender->is_bot)->toBeTrue();
});

it('edits the message it already posted rather than answering twice', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $run = StandupRun::query()->firstOrFail();

    $response = StandupResponse::query()
        ->where('standup_run_id', $run->id)
        ->where('user_id', $this->ada->id)
        ->firstOrFail();

    foreach (['First go', 'Corrected'] as $answer) {
        $this->actingAs($this->ada)
            ->postJson("/w/{$this->workspace->slug}/standup-responses/{$response->id}", [
                'answers' => [$answer],
            ])
            ->assertOk();
    }

    $posted = Message::query()->where('channel_id', $this->channel->id)->get();

    expect($posted)->toHaveCount(1)
        ->and($posted->first()->body)->toContain('Corrected')
        ->and($posted->first()->body)->not->toContain('First go');
});

it('does not post a skip to the channel', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $run = StandupRun::query()->firstOrFail();

    $response = StandupResponse::query()
        ->where('standup_run_id', $run->id)
        ->where('user_id', $this->ada->id)
        ->firstOrFail();

    $this->actingAs($this->ada)
        ->postJson("/w/{$this->workspace->slug}/standup-responses/{$response->id}", [
            'skipped' => true,
        ])
        ->assertOk();

    expect($response->fresh()->answer_message_id)->toBeNull()
        ->and(Message::query()->where('channel_id', $this->channel->id)->count())->toBe(0);
});

it('rolls the run up once everybody has answered', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $run = StandupRun::query()->firstOrFail();

    foreach ([$this->ada, $this->bo] as $index => $person) {
        $response = StandupResponse::query()
            ->where('standup_run_id', $run->id)
            ->where('user_id', $person->id)
            ->firstOrFail();

        $this->actingAs($person)
            ->postJson("/w/{$this->workspace->slug}/standup-responses/{$response->id}", [
                'answers' => ["Working on thing {$index}", ''],
            ])
            ->assertOk();
    }

    $run->refresh();

    expect($run->state)->toBe(StandupRunState::Completed)
        ->and($run->closed_at)->not->toBeNull()
        ->and($run->summary_message_id)->not->toBeNull();

    $summary = Message::query()->findOrFail($run->summary_message_id);

    /*
     * Names, not answers. Both updates went to the channel as they were given,
     * and reprinting them hours later would be the same morning twice — what is
     * left for the summary to say is who the standup reached.
     */
    expect($summary->channel_id)->toBe($this->channel->id)
        ->and($summary->body)->toContain('Answers from')
        ->and($summary->body)->toContain($this->ada->name)
        ->and($summary->body)->toContain($this->bo->name)
        ->and($summary->body)->not->toContain('Working on thing 0')
        // A real message from a real sender, so it renders, paginates and
        // searches like anything else in the channel.
        ->and($summary->type)->toBe(MessageType::Text)
        ->and($summary->sender->is_bot)->toBeTrue();
});

it('closes on the window with whatever it has, and names who is missing', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $run = StandupRun::query()->firstOrFail();

    $response = StandupResponse::query()
        ->where('standup_run_id', $run->id)
        ->where('user_id', $this->ada->id)
        ->firstOrFail();

    $this->actingAs($this->ada)
        ->postJson("/w/{$this->workspace->slug}/standup-responses/{$response->id}", [
            'answers' => ['Shipping the parser'],
        ])
        ->assertOk();

    expect($run->fresh()->isOpen())->toBeTrue();

    // Four hours on: the default close window.
    runStandupsAt('2026-08-10 13:31:00');

    $run->refresh();

    expect($run->state)->toBe(StandupRunState::Completed);

    $summary = Message::query()->findOrFail($run->summary_message_id);

    expect($summary->body)->toContain('Answers from '.$this->ada->name)
        // Named, because a summary that quietly shrinks makes one answer look
        // like a team of one.
        ->and($summary->body)->toContain('No answer yet from '.$this->bo->name);
});

it('posts nothing at all when nobody answered', function () {
    runStandupsAt('2026-08-10 09:30:00');
    runStandupsAt('2026-08-10 13:31:00');

    $run = StandupRun::query()->firstOrFail();

    expect($run->state)->toBe(StandupRunState::Completed)
        ->and($run->summary_message_id)->toBeNull()
        ->and(Message::query()->where('channel_id', $this->channel->id)->count())->toBe(0);
});

it('nudges only the people who have not answered, and only once', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $run = StandupRun::query()->firstOrFail();

    $response = StandupResponse::query()
        ->where('standup_run_id', $run->id)
        ->where('user_id', $this->ada->id)
        ->firstOrFail();

    $this->actingAs($this->ada)
        ->postJson("/w/{$this->workspace->slug}/standup-responses/{$response->id}", [
            'answers' => ['Done'],
        ])
        ->assertOk();

    $before = Message::query()->whereNotNull('conversation_id')->count();

    // 90 minutes on: the default reminder.
    runStandupsAt('2026-08-10 11:05:00');
    runStandupsAt('2026-08-10 11:06:00');

    expect(Message::query()->whereNotNull('conversation_id')->count())
        ->toBe($before + 1)
        ->and($run->fresh()->reminded_at)->not->toBeNull();
});

it('does not nudge when the standup was told not to chase', function () {
    $this->standup->forceFill(['reminder_after_minutes' => null])->save();

    runStandupsAt('2026-08-10 09:30:00');

    $before = Message::query()->whereNotNull('conversation_id')->count();

    runStandupsAt('2026-08-10 11:05:00');

    expect(Message::query()->whereNotNull('conversation_id')->count())->toBe($before);
});

it('closes immediately when the channel has nobody in it', function () {
    $empty = makeChannel($this->workspace);

    $standup = Standup::factory()->forChannel($empty)->daily()->at('10:00')->create();

    runStandupsAt('2026-08-10 10:00:00');

    $run = $standup->runs()->firstOrFail();

    // Nothing to ask, nothing to summarise, and nothing left for the sweeps to
    // pick up every minute until the window expires.
    expect($run->state)->toBe(StandupRunState::Completed)
        ->and($run->summary_message_id)->toBeNull();
});

it('keeps one bot per workspace and reuses it', function () {
    $other = makeWorkspace();

    runStandupsAt('2026-08-10 09:30:00');
    runStandupsAt('2026-08-11 09:30:00');

    expect(User::query()->where('is_bot', true)->count())->toBe(1)
        ->and($other->standups()->count())->toBe(0);
});

/*
 * The two flags are separate for a reason worth pinning down: one that both
 * asked and closed made trying a standup out impossible, because the run it
 * opened was written up again in the same invocation and nobody could answer
 * the prompt they had just been sent.
 */
it('asks without closing under --force', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 15:00:00', 'UTC'));

    try {
        // Well outside the schedule and its catch-up window, which is the whole
        // point of the flag.
        Artisan::call('snack:run-standups', ['--force' => true]);
    } finally {
        CarbonImmutable::setTestNow();
    }

    $run = StandupRun::query()->firstOrFail();

    expect($run->asked_at)->not->toBeNull()
        ->and($run->isOpen())->toBeTrue()
        ->and($run->responses()->count())->toBe(2);
});

it('closes an open run on demand under --close', function () {
    runStandupsAt('2026-08-10 09:30:00');

    $run = StandupRun::query()->firstOrFail();

    $response = StandupResponse::query()
        ->where('standup_run_id', $run->id)
        ->where('user_id', $this->ada->id)
        ->firstOrFail();

    $this->actingAs($this->ada)
        ->postJson("/w/{$this->workspace->slug}/standup-responses/{$response->id}", [
            'answers' => ['Done'],
        ])
        ->assertOk();

    // Long before the four-hour window would have closed it.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:40:00', 'UTC'));

    try {
        Artisan::call('snack:run-standups', ['--close' => true]);
    } finally {
        CarbonImmutable::setTestNow();
    }

    expect($run->fresh()->state)->toBe(StandupRunState::Completed)
        ->and($run->fresh()->summary_message_id)->not->toBeNull();
});

it('does not spend a seat on the bot', function () {
    $before = $this->workspace->humanMemberCount();

    runStandupsAt('2026-08-10 09:30:00');

    expect($this->workspace->fresh()->humanMemberCount())->toBe($before);
});
