<?php

use App\Models\User;

/**
 * The record of huddles that have happened.
 *
 * A huddle leaves no message behind, so this list is the only evidence a call
 * took place — which makes its scoping the whole story: it names who was talking
 * to whom, and a row that should not be in it leaks exactly that.
 */
it('lists a finished huddle from a channel the viewer is in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $huddle = makeHuddle($channel, [$owner]);
    $huddle->update(['ended_at' => now()]);

    $this->actingAs($owner)
        ->get(route('huddles.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('huddles/index')
            ->has('huddles', 1)
            ->where('huddles.0.id', $huddle->public_id)
            ->where('huddles.0.is_live', false)
            ->where('huddles.0.container.slug', $channel->slug)
            // Everyone who was on the call, not who is still in the room —
            // nobody is, it ended.
            ->has('huddles.0.participants', 1));
});

it('keeps a live huddle in the list, without a duration', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    makeHuddle($channel, [$owner]);

    // Still accruing one; the history says how long a call *ran*, and this one
    // has not finished running.
    $this->actingAs($owner)
        ->get(route('huddles.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('huddles.0.is_live', true)
            ->where('huddles.0.duration_seconds', null));
});

it('reports how long a finished huddle ran', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $huddle = makeHuddle($channel, [$owner]);
    $huddle->update([
        'started_at' => now()->subMinutes(12),
        'ended_at' => now(),
    ]);

    // Computed server-side, so a reader's clock being minutes out cannot report
    // a call as having run for minus three.
    $this->actingAs($owner)
        ->get(route('huddles.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('huddles.0.duration_seconds', 720));
});

it('leaves out a huddle in a channel the viewer never joined', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $outsider = joinWorkspace($workspace);

    makeHuddle($channel, [$owner]);

    // A public channel they could join is still not somewhere they were.
    $this->actingAs($outsider)
        ->get(route('huddles.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('huddles', 0));
});

it('never shows a huddle from two other people\'s DM', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $bo = joinWorkspace($workspace);
    $stranger = joinWorkspace($workspace);

    $conversation = makeConversation($workspace, [$owner, $bo]);
    makeHuddle($conversation, [$owner, $bo]);

    /*
     * The strongest version of the scoping rule: the participant list alone
     * would say who is talking to whom, which is the fact a DM exists to keep.
     */
    $this->actingAs($stranger)
        ->get(route('huddles.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('huddles', 0));
});

it('shows a DM huddle to the people who were in the conversation', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $bo = joinWorkspace($workspace);

    $conversation = makeConversation($workspace, [$owner, $bo]);
    $huddle = makeHuddle($conversation, [$owner, $bo]);

    $this->actingAs($bo)
        ->get(route('huddles.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('huddles', 1)
            ->where('huddles.0.id', $huddle->public_id)
            ->where('huddles.0.container.kind', 'conversation')
            // The opaque public id, never the primary key.
            ->where('huddles.0.container.id', $conversation->public_id));
});

it('leaves out another workspace entirely', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $elsewhere = makeWorkspace();
    $elsewhereChannel = makeChannel($elsewhere);
    joinWorkspace($elsewhere, $owner);
    addToChannel($elsewhereChannel, $owner);

    makeHuddle($elsewhereChannel, [$owner]);

    $this->actingAs($owner)
        ->get(route('huddles.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('huddles', 0));
});

it('refuses someone who is not in the workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();

    $this->actingAs(User::factory()->create())
        ->get(route('huddles.index', $workspace))
        ->assertNotFound();
});

it('puts the most recent huddle first', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $older = makeHuddle($channel, [$owner]);
    $older->update(['started_at' => now()->subHours(3), 'ended_at' => now()->subHours(2)]);

    $newer = makeHuddle($channel, [$owner]);
    $newer->update(['started_at' => now()->subMinutes(10), 'ended_at' => now()]);

    $this->actingAs($owner)
        ->get(route('huddles.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('huddles.0.id', $newer->public_id)
            ->where('huddles.1.id', $older->public_id));
});
