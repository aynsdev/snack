<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Standup;
use App\Models\StandupResponse;
use App\Models\StandupRun;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
});

/**
 * The shape the form posts.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function standupPayload(Channel $channel, array $overrides = []): array
{
    return [
        'name' => 'Daily standup',
        'channel_id' => $channel->id,
        'questions' => ['What are you working on?', 'Anything blocking you?'],
        'days_of_week' => [1, 2, 3, 4, 5],
        'ask_at' => '09:30',
        'timezone' => 'Europe/London',
        'reminder_after_minutes' => 90,
        'closes_after_minutes' => 240,
        'is_active' => true,
        ...$overrides,
    ];
}

it('lets an admin see the standups screen', function () {
    $this->actingAs($this->owner)
        ->get("/w/{$this->workspace->slug}/standups")
        ->assertOk();
});

it('refuses an ordinary member', function () {
    $this->actingAs($this->member)
        ->get("/w/{$this->workspace->slug}/standups")
        ->assertForbidden();
});

it('creates a standup and installs the bot in its channel', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel))
        ->assertRedirect();

    $standup = Standup::query()->firstOrFail();

    expect($standup->workspace_id)->toBe($this->workspace->id)
        ->and($standup->channel_id)->toBe($this->channel->id)
        ->and($standup->questionList())->toHaveCount(2)
        ->and($standup->scheduleSummary())->toBe('Weekdays at 09:30 (Europe/London)');

    $bot = User::query()->where('is_bot', true)->firstOrFail();

    expect($this->channel->fresh()->hasMember($bot))->toBeTrue();
});

it('records the creation in the audit log', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel));

    expect(AuditLog::query()->where('action', AuditAction::StandupCreated)->exists())->toBeTrue();
});

it('refuses a member trying to create one', function () {
    $this->actingAs($this->member)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel))
        ->assertForbidden();

    expect(Standup::query()->count())->toBe(0);
});

it('refuses a channel from another workspace', function () {
    $elsewhere = makeChannel(makeWorkspace());

    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($elsewhere))
        ->assertSessionHasErrors('channel_id');
});

it('refuses an archived channel', function () {
    $archived = makeChannel($this->workspace, attributes: ['is_archived' => true]);

    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($archived))
        ->assertSessionHasErrors('channel_id');
});

it('needs at least one question', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel, [
            // Blank rows are what an abandoned "add another" looks like; they
            // are stripped, which leaves nothing, which is the error.
            'questions' => ['', '   '],
        ]))
        ->assertSessionHasErrors('questions');
});

it('drops the blank rows a repeater leaves behind', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel, [
            'questions' => ['What are you working on?', '', 'Blocked?'],
        ]))
        ->assertRedirect();

    expect(Standup::query()->firstOrFail()->questionList())
        ->toBe(['What are you working on?', 'Blocked?']);
});

it('refuses a timezone the server does not know', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel, [
            'timezone' => 'Middle/Earth',
        ]))
        ->assertSessionHasErrors('timezone');
});

it('refuses a time that is not HH:MM', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel, [
            'ask_at' => 'half nine',
        ]))
        ->assertSessionHasErrors('ask_at');
});

it('updates a standup and puts the bot in its new channel', function () {
    $standup = Standup::factory()->forChannel($this->channel)->create();
    $moved = makeChannel($this->workspace);

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/standups/{$standup->id}", standupPayload($moved, [
            'name' => 'Morning check-in',
            'ask_at' => '10:15',
            'reminder_after_minutes' => null,
        ]))
        ->assertRedirect();

    $standup->refresh();

    expect($standup->name)->toBe('Morning check-in')
        ->and($standup->channel_id)->toBe($moved->id)
        ->and($standup->askAtLabel())->toBe('10:15')
        // Null is a value here, not an omission: it is how a team says "stop
        // chasing us".
        ->and($standup->reminder_after_minutes)->toBeNull();

    $bot = User::query()->where('is_bot', true)->firstOrFail();

    expect($moved->fresh()->hasMember($bot))->toBeTrue();
});

it('narrows a standup to selected people', function () {
    $other = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel, [
            'participant_ids' => [$this->member->id, $other->id],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Standup::query()->firstOrFail()->selectedParticipantIds())
        ->toEqualCanonicalizing([$this->member->id, $other->id]);
});

it('goes back to asking the whole channel when the selection is cleared', function () {
    $standup = Standup::factory()->forChannel($this->channel)->create([
        'participant_ids' => [$this->member->id],
    ]);

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/standups/{$standup->id}", standupPayload($this->channel, [
            'participant_ids' => null,
        ]))
        ->assertRedirect();

    expect($standup->fresh()->restrictsParticipants())->toBeFalse();
});

it('keeps the selection when an edit does not mention it', function () {
    $standup = Standup::factory()->forChannel($this->channel)->create([
        'participant_ids' => [$this->member->id],
    ]);

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/standups/{$standup->id}", standupPayload($this->channel));

    expect($standup->fresh()->selectedParticipantIds())->toBe([$this->member->id]);
});

it('refuses selecting somebody from another workspace', function () {
    [, $stranger] = makeWorkspaceWithOwner();

    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel, [
            'participant_ids' => [$stranger->id],
        ]))
        ->assertSessionHasErrors('participant_ids.0');

    expect(Standup::query()->exists())->toBeFalse();
});

it('refuses an empty selection', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/standups", standupPayload($this->channel, [
            'participant_ids' => [],
        ]))
        ->assertSessionHasErrors('participant_ids');
});

it('counts only the selected people who are in the channel', function () {
    $other = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    addToChannel($this->channel, $this->member);
    addToChannel($this->channel, $other);

    Standup::factory()->forChannel($this->channel)->create([
        'participant_ids' => [$this->member->id],
    ]);

    $this->actingAs($this->owner)
        ->get("/w/{$this->workspace->slug}/standups")
        ->assertInertia(fn ($page) => $page
            ->where('standups.0.participant_count', 1)
            ->where('standups.0.participant_ids', [$this->member->id])
            ->has('people'));
});

it('pauses a standup without losing its questions', function () {
    $standup = Standup::factory()->forChannel($this->channel)->create();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/standups/{$standup->id}", standupPayload($this->channel, [
            'is_active' => false,
        ]))
        ->assertRedirect();

    expect($standup->fresh()->is_active)->toBeFalse()
        ->and($standup->fresh()->questionList())->not->toBeEmpty();
});

it('deletes a standup and its history', function () {
    $standup = Standup::factory()->forChannel($this->channel)->create();
    $run = StandupRun::factory()->create(['standup_id' => $standup->id]);
    StandupResponse::factory()->create([
        'standup_run_id' => $run->id,
        'user_id' => $this->member->id,
    ]);

    $this->actingAs($this->owner)
        ->delete("/w/{$this->workspace->slug}/standups/{$standup->id}")
        ->assertRedirect();

    expect(Standup::query()->count())->toBe(0)
        ->and(StandupRun::query()->count())->toBe(0)
        ->and(StandupResponse::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditAction::StandupDeleted)->exists())->toBeTrue();
});

it('refuses a standup from another workspace at the router', function () {
    $elsewhere = Standup::factory()->forChannel(makeChannel(makeWorkspace()))->create();

    $this->actingAs($this->owner)
        ->delete("/w/{$this->workspace->slug}/standups/{$elsewhere->id}")
        ->assertNotFound();
});

/*
 * Answering is a different right from configuring — see StandupPolicy.
 */

it('refuses somebody else\'s standup, without confirming it exists', function () {
    $standup = Standup::factory()->forChannel($this->channel)->create();
    $run = StandupRun::factory()->create(['standup_id' => $standup->id]);

    $response = StandupResponse::factory()->create([
        'standup_run_id' => $run->id,
        'user_id' => $this->member->id,
    ]);

    // The owner administers this standup and still may not fill in a
    // colleague's morning.
    $this->actingAs($this->owner)
        ->postJson("/w/{$this->workspace->slug}/standup-responses/{$response->id}", [
            'answers' => ['Something'],
        ])
        ->assertNotFound();
});

it('refuses an answer once the run has been posted', function () {
    $standup = Standup::factory()->forChannel($this->channel)->create();
    $run = StandupRun::factory()->closed()->create(['standup_id' => $standup->id]);

    $response = StandupResponse::factory()->create([
        'standup_run_id' => $run->id,
        'user_id' => $this->member->id,
    ]);

    $this->actingAs($this->member)
        ->postJson("/w/{$this->workspace->slug}/standup-responses/{$response->id}", [
            'answers' => ['Too late'],
        ])
        ->assertForbidden();
});
