<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Services\BiteBot;
use Illuminate\Support\Facades\Storage;

/**
 * Who the composer offers when you type an "@".
 *
 * The rule is that the picker must match what the server will actually do with
 * the handle once it is sent: offering somebody who would be ignored teaches
 * people they notified a colleague who never heard them, and leaving out
 * somebody who would have answered teaches them a feature does not work here.
 */
beforeEach(function () {
    // Bite's avatar is written on account creation; nothing here should touch
    // the real disk.
    Storage::fake('public');

    config()->set('snack.bite.enabled', true);
    config()->set('ai.default', 'anthropic');
    config()->set('ai.providers.anthropic.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
});

function mentionableIn(Channel $channel, string $term = ''): string
{
    return route('channels.mentionable.index', [
        'workspace' => $channel->workspace->slug,
        'channel' => $channel->slug,
        'q' => $term,
    ]);
}

it('offers the people in the channel', function () {
    $channel = makeChannel($this->workspace);
    $colleague = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    addToChannel($channel, $this->owner);
    addToChannel($channel, $colleague);

    $this->actingAs($this->owner)
        ->getJson(mentionableIn($channel))
        ->assertOk()
        ->assertJsonPath('people.0.id', $colleague->id)
        // Mentioning yourself notifies nobody, so you are not in your own list.
        ->assertJsonCount(1, 'people');
});

it('does not offer someone who is not in the channel', function () {
    $channel = makeChannel($this->workspace);
    $outsider = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    addToChannel($channel, $this->owner);

    $this->actingAs($this->owner)
        ->getJson(mentionableIn($channel, $outsider->name))
        ->assertOk()
        ->assertJsonCount(0, 'people');
});

/**
 * The bug this file was written for.
 *
 * Bite joins a channel as it answers its first summon there, so a picker that
 * offered only members could only ever suggest it somewhere it had already been
 * typed by hand. In a private channel — no public history to have seen the
 * handle in, nobody else to break the ice — that is indistinguishable from Bite
 * not working in private channels at all.
 */
it('offers Bite in a private channel it has never spoken in', function () {
    $bot = app(BiteBot::class)->accountFor($this->workspace);

    $channel = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($channel, $this->owner);

    expect($channel->hasMember($bot))->toBeFalse();

    $this->actingAs($this->owner)
        ->getJson(mentionableIn($channel, 'bit'))
        ->assertOk()
        ->assertJsonPath('people.0.id', $bot->id);
});

it('offers Bite alongside the channel roster when nothing is typed yet', function () {
    $bot = app(BiteBot::class)->accountFor($this->workspace);

    $channel = makeChannel($this->workspace, ChannelType::Private);
    $colleague = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    addToChannel($channel, $this->owner);
    addToChannel($channel, $colleague);

    $response = $this->actingAs($this->owner)
        ->getJson(mentionableIn($channel))
        ->assertOk();

    expect(collect($response->json('people'))->pluck('id')->all())
        ->toContain($bot->id, $colleague->id);
});

it('offers Bite only once in a channel it has already joined', function () {
    $bot = app(BiteBot::class)->accountFor($this->workspace);

    $channel = makeChannel($this->workspace);
    addToChannel($channel, $this->owner);
    app(BiteBot::class)->joinChannel($channel, $bot);

    $response = $this->actingAs($this->owner)
        ->getJson(mentionableIn($channel, 'bite'))
        ->assertOk();

    expect(collect($response->json('people'))->where('id', $bot->id))->toHaveCount(1);
});

/**
 * An offer that summons nothing is worse than no offer: the person types the
 * handle the picker gave them and waits for an answer that is never coming.
 */
it('does not offer Bite when the assistant is switched off', function () {
    app(BiteBot::class)->accountFor($this->workspace);

    config()->set('snack.bite.enabled', false);

    $channel = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($channel, $this->owner);

    $this->actingAs($this->owner)
        ->getJson(mentionableIn($channel, 'bite'))
        ->assertOk()
        ->assertJsonCount(0, 'people');
});

it('does not offer Bite before the workspace has an account for it', function () {
    $channel = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($channel, $this->owner);

    $this->actingAs($this->owner)
        ->getJson(mentionableIn($channel, 'bite'))
        ->assertOk()
        ->assertJsonCount(0, 'people');
});

/**
 * The roster is not a directory. Someone who may only look at a public channel
 * cannot write in it, so they have no use for its member list.
 */
it('refuses someone who cannot post in the channel', function () {
    $channel = makeChannel($this->workspace);
    $onlooker = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->actingAs($onlooker)
        ->getJson(mentionableIn($channel))
        ->assertForbidden();
});
