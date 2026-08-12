<?php

use App\Actions\Messages\SendMessage;
use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Jobs\AnswerWithBite;
use App\Models\Message;
use App\Models\User;
use App\Services\BiteBot;
use App\Services\BiteSummons;
use App\Services\StandupBot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * What counts as talking to Bite.
 *
 * The two things worth protecting here are opposites. Bite has to answer when
 * it is addressed, or the feature does not exist. And it must never answer when
 * it is not — because a bot that replies to a bot is a queue that never drains,
 * and one that replies to everything in a channel is one that gets switched off
 * within a day.
 */
beforeEach(function () {
    config()->set('snack.bite.enabled', true);
    config()->set('ai.default', 'mistral');
    config()->set('ai.providers.mistral.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    addToChannel($this->channel, $this->ada);
});

/**
 * Post as a real person through the real pipeline, which is what decides
 * whether Bite is summoned.
 */
function say(string $body, ?User $sender = null): Message
{
    return app(SendMessage::class)->handle(
        container: test()->channel,
        sender: $sender ?? test()->ada,
        body: $body,
    );
}

it('is summoned when named in a channel', function () {
    Queue::fake();

    $message = say('@bite what did we decide about pricing?');

    expect(app(BiteSummons::class)->summonedBy($message))->not->toBeNull();

    Queue::assertPushed(AnswerWithBite::class);
});

it('is not summoned by a message that does not name it', function () {
    Queue::fake();

    $message = say('what did we decide about pricing?');

    expect(app(BiteSummons::class)->summonedBy($message))->toBeNull();

    Queue::assertNotPushed(AnswerWithBite::class);
});

/**
 * The parser strips code before looking for handles, which is what stops a
 * snippet from notifying anybody. Bite has to inherit that: pasting a shell
 * command that happens to contain the word must not wake the assistant.
 */
it('is not summoned by its name inside a code fence', function () {
    Queue::fake();

    $message = say("here is the fix:\n```\ncurl @bite/health\n```");

    expect(app(BiteSummons::class)->summonedBy($message))->toBeNull();

    Queue::assertNotPushed(AnswerWithBite::class);
});

/**
 * The loop guard. Bite's own answers mention people and quote channels, so
 * without this a single unlucky reply is an infinite exchange between two bot
 * accounts, billed per turn.
 */
it('is never summoned by another bot', function () {
    Queue::fake();

    $bot = app(BiteBot::class)->accountFor($this->workspace);
    addToChannel($this->channel, $bot);

    $message = say('@bite are you there?', $bot);

    expect(app(BiteSummons::class)->summonedBy($message))->toBeNull();

    Queue::assertNotPushed(AnswerWithBite::class);
});

it('is not summoned while the feature is switched off', function () {
    config()->set('snack.bite.enabled', false);

    Queue::fake();

    $message = say('@bite hello');

    expect(app(BiteSummons::class)->summonedBy($message))->toBeNull();

    Queue::assertNotPushed(AnswerWithBite::class);
});

/**
 * A flag on with no key behind it is the configuration mistake that would
 * otherwise turn every message into a failed job rather than into nothing.
 */
it('is not summoned when the provider has no key', function () {
    config()->set('ai.providers.mistral.key', null);

    Queue::fake();

    $message = say('@bite hello');

    expect(app(BiteSummons::class)->summonedBy($message))->toBeNull();
});

it('joins the channel it is summoned into', function () {
    Queue::fake();

    $message = say('@bite hello');

    $bot = app(BiteSummons::class)->summonedBy($message);

    expect($bot)->not->toBeNull()
        ->and($this->channel->members()->where('user_id', $bot->id)->exists())->toBeTrue();
});

/**
 * A DM with Bite is a DM with Bite. Requiring the handle in a two-person
 * conversation would only be a way to get no answer.
 */
it('is summoned by any message in a conversation it is part of', function () {
    Queue::fake();

    $bot = app(BiteBot::class)->accountFor($this->workspace);
    $conversation = makeConversation($this->workspace, [$this->ada, $bot]);

    $message = app(SendMessage::class)->handle(
        container: $conversation,
        sender: $this->ada,
        body: 'what is on my plate today?',
    );

    expect(app(BiteSummons::class)->summonedBy($message))->not->toBeNull();
});

it('is not summoned in a conversation it is not part of', function () {
    Queue::fake();

    app(BiteBot::class)->accountFor($this->workspace);

    $conversation = makeConversation($this->workspace, [$this->ada, $this->owner]);

    $message = app(SendMessage::class)->handle(
        container: $conversation,
        sender: $this->ada,
        body: 'what is on my plate today?',
    );

    expect(app(BiteSummons::class)->summonedBy($message))->toBeNull();
});

/**
 * The handle is matched, not looked up, precisely so that a workspace whose
 * account had to settle for `bite-7` still answers to `@bite`. Without this the
 * second workspace on an installation has an assistant nobody can address.
 */
it('answers to the bare handle even when its account holds a qualified one', function () {
    Queue::fake();

    // Take the bare handle with an unrelated account, forcing the fallback.
    User::factory()->create(['username' => 'bite']);

    $bot = app(BiteBot::class)->accountFor($this->workspace);

    expect($bot->username)->toBe("bite-{$this->workspace->id}");

    $message = say('@bite hello');

    expect(app(BiteSummons::class)->summonedBy($message)?->id)->toBe($bot->id);
});

/**
 * Bite appears in the DM list, the mention autocomplete, and the member roster,
 * so a monogram would read as an account somebody forgot to finish setting up.
 * The bundled artwork goes through the same processor a person's upload does.
 */
it('gives its account the bundled avatar', function () {
    Storage::fake(config('snack.avatars.disk', 'public'));

    $bot = app(BiteBot::class)->accountFor($this->workspace);

    expect($bot->avatar_path)->not->toBeNull()
        ->and($bot->avatar_path)->toStartWith("avatars/{$bot->id}/");

    Storage::disk(config('snack.avatars.disk', 'public'))->assertExists($bot->avatar_path);

    // Square and bounded, like every other avatar in the product — the source
    // file is 1254px, so it must have been re-encoded rather than copied.
    $image = getimagesizefromstring(
        Storage::disk(config('snack.avatars.disk', 'public'))->get($bot->avatar_path),
    );

    expect($image[0])->toBe(512)->and($image[1])->toBe(512);
});

/**
 * A key derived from the bytes rather than a fresh ULID, so resolving the
 * account twice does not accumulate identical copies of fixed artwork.
 */
it('does not rewrite the avatar every time the account is resolved', function () {
    Storage::fake(config('snack.avatars.disk', 'public'));

    $first = app(BiteBot::class)->accountFor($this->workspace)->avatar_path;
    $second = app(BiteBot::class)->accountFor($this->workspace)->avatar_path;

    expect($second)->toBe($first)
        ->and(Storage::disk(config('snack.avatars.disk', 'public'))->allFiles())->toHaveCount(1);
});

/**
 * An installation whose bot account predates the artwork picks it up on the
 * next thing the bot does, rather than needing a migration.
 */
it('backfills the avatar onto an account that has none', function () {
    Storage::fake(config('snack.avatars.disk', 'public'));

    $bot = app(BiteBot::class)->accountFor($this->workspace);
    $bot->forceFill(['avatar_path' => null])->save();

    expect(app(BiteBot::class)->accountFor($this->workspace)->avatar_path)->not->toBeNull();
});

/**
 * Standup has no artwork, and must not silently inherit Bite's.
 */
it('leaves the standup bot without one', function () {
    Storage::fake(config('snack.avatars.disk', 'public'));

    expect(app(StandupBot::class)->accountFor($this->workspace)->avatar_path)->toBeNull();
});

/**
 * Bots are not colleagues. A workspace that turns Bite on should not lose a
 * seat, and the admin roster should not grow a row nobody can remove.
 */
it('does not count against the workspace seat limit', function () {
    $before = $this->workspace->humanMemberCount();

    app(BiteBot::class)->accountFor($this->workspace);

    expect($this->workspace->fresh()->humanMemberCount())->toBe($before);
});

/**
 * Each workspace gets its own account, which is the tenant boundary every other
 * check in the application already assumes.
 */
it('gives each workspace its own account', function () {
    [$other] = makeWorkspaceWithOwner();

    $first = app(BiteBot::class)->accountFor($this->workspace);
    $second = app(BiteBot::class)->accountFor($other);

    expect($first->id)->not->toBe($second->id)
        ->and(Collection::make([$first, $second])->pluck('is_bot')->all())->toBe([true, true]);
});

/**
 * A private channel is still a channel: being named in one Bite has not been
 * invited to must not reach it. It is added on summon, so the guard that
 * matters is that the *asker* was in it — which the pipeline settles before
 * this is ever reached.
 */
it('answers in a private channel it was named in', function () {
    Queue::fake();

    $private = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($private, $this->ada);

    $message = app(SendMessage::class)->handle(
        container: $private,
        sender: $this->ada,
        body: '@bite what is this channel for?',
    );

    expect(app(BiteSummons::class)->summonedBy($message))->not->toBeNull();
});
