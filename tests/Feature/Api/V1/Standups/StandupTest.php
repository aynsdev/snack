<?php

use App\Enums\WorkspaceRole;
use App\Models\Standup;
use App\Models\StandupResponse;
use App\Models\StandupRun;
use App\Models\User;

/**
 * Standups over the API: configuring them is an admin's right, answering one
 * is the asked person's.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    addToChannel($this->channel, $this->member);

    $this->base = "/api/v1/w/{$this->workspace->slug}";

    $this->payload = [
        'name' => 'Daily standup',
        'channel_id' => $this->channel->id,
        'questions' => ['What are you working on?', 'Anything blocking you?'],
        'days_of_week' => [1, 2, 3, 4, 5],
        'ask_at' => '09:30',
        'timezone' => 'Europe/London',
        'reminder_after_minutes' => 90,
        'closes_after_minutes' => 240,
        'is_active' => true,
    ];
});

it('shows an admin the standups screen', function () {
    Standup::factory()->forChannel($this->channel)->create();

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/standups")
        ->assertOk()
        ->assertJsonCount(1, 'data.standups')
        ->assertJsonPath('data.standups.0.participant_count', 1)
        ->assertJsonPath('data.channels.0.id', $this->channel->id)
        ->assertJsonPath('data.bot.exists', false)
        ->assertJsonStructure(['data' => ['timezones', 'defaults' => ['questions', 'ask_at'], 'max_questions']]);
});

it('refuses the standups screen to an ordinary member', function () {
    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/standups")
        ->assertForbidden();
});

it('creates a standup and installs the bot', function () {
    $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/standups", $this->payload)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Daily standup')
        ->assertJsonPath('data.channel.id', $this->channel->id)
        ->assertJsonPath('data.schedule_summary', 'Weekdays at 09:30 (Europe/London)');

    $bot = User::query()->where('is_bot', true)->firstOrFail();

    expect($this->channel->fresh()->hasMember($bot))->toBeTrue();
});

it('refuses a channel from another workspace', function () {
    $elsewhere = makeChannel(makeWorkspace());

    $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/standups", [...$this->payload, 'channel_id' => $elsewhere->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channel_id');
});

it('updates and deletes a standup', function () {
    $id = $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/standups", $this->payload)
        ->json('data.id');

    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/standups/{$id}", [...$this->payload, 'name' => 'Morning check-in', 'ask_at' => '10:00'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Morning check-in')
        ->assertJsonPath('data.ask_at', '10:00');

    $this->withToken(mobileToken($this->owner))
        ->deleteJson("{$this->base}/standups/{$id}")
        ->assertNoContent();

    expect(Standup::query()->find($id))->toBeNull();
});

it('lets the asked person answer, and nobody else', function () {
    $standup = Standup::factory()->forChannel($this->channel)->create([
        'questions' => ['What are you working on?'],
    ]);
    $run = StandupRun::factory()->create(['standup_id' => $standup->id]);

    $response = StandupResponse::factory()->create([
        'standup_run_id' => $run->id,
        'user_id' => $this->member->id,
    ]);

    $this->withToken(mobileToken($this->owner))
        ->postJson("{$this->base}/standup-responses/{$response->id}", ['answers' => ['Something']])
        ->assertNotFound();

    auth()->forgetGuards();

    $this->withToken(mobileToken($this->member))
        ->postJson("{$this->base}/standup-responses/{$response->id}", ['answers' => ['Shipping the parser']])
        ->assertOk()
        ->assertJsonStructure(['standup']);

    expect($response->fresh()->submitted_at)->not->toBeNull();
});
