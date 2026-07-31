<?php

use App\Enums\MentionType;
use App\Enums\PresenceStatus;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\Mention;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\UnreadCounter;
use Illuminate\Testing\TestResponse;

/**
 * Mentions.
 *
 * The grammar itself is settled in tests/Unit/Messages/MentionParserTest.php.
 * What matters here is what a handle is allowed to *reach*: a mention is a
 * client-supplied identifier, and resolving it against the users table rather
 * than against the container would let it notify — and probe for — people who
 * cannot see the message.
 */
function sendWithMentions(
    Workspace $workspace,
    Channel $channel,
    string $body,
): TestResponse {
    return test()->postJson(
        route('channels.messages.store', ['workspace' => $workspace->slug, 'channel' => $channel->slug]),
        ['body' => $body],
    );
}

/**
 * A workspace, a channel, its author, and a member who can be mentioned.
 *
 * @return array{0: Workspace, 1: User, 2: Channel, 3: User}
 */
function makeMentionFixture(): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $mentioned = joinWorkspace($workspace);
    $mentioned->forceFill(['username' => 'bo'])->save();
    addToChannel($channel, $mentioned);

    return [$workspace, $owner, $channel, $mentioned];
}

// -- Resolution --------------------------------------------------------------

it('records a mention for a channel member', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Morning @bo')->assertCreated();

    $mention = Mention::query()->firstOrFail();

    expect($mention->mentioned_user_id)->toBe($bo->id)
        ->and($mention->mention_type)->toBe(MentionType::User)
        ->and($mention->workspace_id)->toBe($workspace->id)
        ->and($mention->read_at)->toBeNull();
});

it('ignores a handle belonging to someone not in the channel', function () {
    [$workspace, $owner, $channel] = makeMentionFixture();

    $outsider = joinWorkspace($workspace);
    $outsider->forceFill(['username' => 'cy'])->save();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Hello @cy')->assertCreated();

    /*
     * No row, so no badge and no notification pointing at a message they cannot
     * open — and no way to use a mention to discover who exists.
     */
    expect(Mention::query()->where('mentioned_user_id', $outsider->id)->count())->toBe(0);
});

it('ignores a handle nobody owns', function () {
    [$workspace, $owner, $channel] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Hello @nobody_at_all')->assertCreated();

    expect(Mention::count())->toBe(0);
});

it('does not mention the author', function () {
    [$workspace, $owner, $channel] = makeMentionFixture();
    $owner->forceFill(['username' => 'ada'])->save();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Note to self @ada')->assertCreated();

    // A badge for something you just wrote is pure noise.
    expect(Mention::query()->where('mentioned_user_id', $owner->id)->count())->toBe(0);
});

it('does not mention someone named only inside code', function () {
    [$workspace, $owner, $channel] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'the flag is `@bo`')->assertCreated();

    expect(Mention::count())->toBe(0);
});

// -- Broadcast mentions ------------------------------------------------------

it('expands @everyone to the channel for someone allowed to use it', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, '@everyone standup in five')->assertCreated();

    $mentions = Mention::query()->get();

    // Everyone in the channel except the author.
    expect($mentions)->toHaveCount(1)
        ->and($mentions->first()->mentioned_user_id)->toBe($bo->id)
        ->and($mentions->first()->mention_type)->toBe(MentionType::Everyone);
});

it('refuses to expand @everyone for a member without the right', function () {
    [$workspace, , $channel] = makeMentionFixture();

    $plain = joinWorkspace($workspace, role: WorkspaceRole::Member);
    addToChannel($channel, $plain);

    $this->actingAs($plain);
    sendWithMentions($workspace, $channel, '@everyone look at this')->assertCreated();

    // The words still send — they are just words — but they reach nobody.
    expect(Mention::count())->toBe(0);
});

it('narrows @here to people who are online', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $offline = joinWorkspace($workspace);
    addToChannel($channel, $offline);

    $bo->forceFill(['presence_status' => PresenceStatus::Online])->save();
    $offline->forceFill(['presence_status' => PresenceStatus::Offline])->save();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, '@here quick question')->assertCreated();

    // The distinction @here exists to draw: most of the room is asleep.
    expect(Mention::query()->pluck('mentioned_user_id')->all())->toBe([$bo->id]);
});

it('prefers a direct mention over a broadcast for the same person', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();
    $bo->forceFill(['presence_status' => PresenceStatus::Online])->save();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, '@everyone — especially @bo')->assertCreated();

    // Being named is louder than being included.
    expect(Mention::query()->where('mentioned_user_id', $bo->id)->count())->toBe(1)
        ->and(Mention::query()->where('mentioned_user_id', $bo->id)->first()->mention_type)
        ->toBe(MentionType::User);
});

// -- Editing -----------------------------------------------------------------

it('adds a mention introduced by an edit', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Morning all')->assertCreated();

    $message = Message::query()->latest('id')->firstOrFail();

    $this->patchJson(
        route('messages.update', ['workspace' => $workspace->slug, 'message' => $message->id]),
        ['body' => 'Morning @bo'],
    )->assertOk();

    expect(Mention::query()->where('mentioned_user_id', $bo->id)->count())->toBe(1);
});

it('removes a mention taken out by an edit', function () {
    [$workspace, $owner, $channel] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Morning @bo')->assertCreated();

    $message = Message::query()->latest('id')->firstOrFail();

    $this->patchJson(
        route('messages.update', ['workspace' => $workspace->slug, 'message' => $message->id]),
        ['body' => 'Morning all'],
    )->assertOk();

    expect(Mention::count())->toBe(0);
});

it('keeps a surviving mention read after an unrelated edit', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Morning @bo')->assertCreated();

    $message = Message::query()->latest('id')->firstOrFail();
    Mention::query()->update(['read_at' => now()]);

    $this->patchJson(
        route('messages.update', ['workspace' => $workspace->slug, 'message' => $message->id]),
        ['body' => 'Morning @bo, and welcome'],
    )->assertOk();

    // Fixing a typo must not re-badge everyone who had already read it.
    expect(Mention::query()->where('mentioned_user_id', $bo->id)->first()->read_at)
        ->not->toBeNull();
});

// -- Badges ------------------------------------------------------------------

it('counts unread mentions per channel', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Morning @bo')->assertCreated();

    $counts = app(UnreadCounter::class)->forChannels($workspace, $bo);

    expect($counts[$channel->id]['mentions'])->toBe(1);
});

it('clears the mention badge when the channel is read', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Morning @bo')->assertCreated();

    $counter = app(UnreadCounter::class);
    expect($counter->mentionCount($workspace, $bo))->toBe(1);

    $this->actingAs($bo);
    $this->postJson(route('channels.read.store', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]))->assertOk();

    // A badge that survives having read the message it points at is one people
    // learn to ignore.
    expect($counter->mentionCount($workspace, $bo))->toBe(0);
});

it('leaves a mention unread if the reader has not reached it', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'First, @bo')->assertCreated();
    $first = Message::query()->latest('id')->firstOrFail();

    sendWithMentions($workspace, $channel, 'And later, @bo')->assertCreated();

    $this->actingAs($bo);
    $this->postJson(route('channels.read.store', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]), ['last_read_message_id' => $first->id])->assertOk();

    // Bounded by the pointer: a mention further down still counts.
    expect(app(UnreadCounter::class)->mentionCount($workspace, $bo))->toBe(1);
});

it('does not count a mention from another workspace', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Morning @bo')->assertCreated();

    expect(app(UnreadCounter::class)->mentionCount(makeWorkspace(), $bo))->toBe(0);
});

it('does not count a mention on a deleted message', function () {
    [$workspace, $owner, $channel, $bo] = makeMentionFixture();

    $this->actingAs($owner);
    sendWithMentions($workspace, $channel, 'Morning @bo')->assertCreated();

    $message = Message::query()->latest('id')->firstOrFail();

    $this->deleteJson(route('messages.destroy', [
        'workspace' => $workspace->slug,
        'message' => $message->id,
    ]))->assertOk();

    expect(app(UnreadCounter::class)->mentionCount($workspace, $bo))->toBe(0);
});
