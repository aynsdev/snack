<?php

use App\Enums\NotificationReason;
use App\Models\Mention;
use App\Models\Message;
use App\Models\Notification;
use App\Models\ThreadSubscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Activity: notifications, mentions, and followed threads as tabs over the one
 * question they all answer.
 *
 * The tab decides which queries run, so these tests are as much about what is
 * *absent* from the payload as about what is in it — a tab that quietly built
 * all three lists would work perfectly and cost three times what it should.
 */
function visitActivity(Workspace $workspace, ?string $tab = null): TestResponse
{
    return test()->get(route('activity.index', array_filter([
        'workspace' => $workspace->slug,
        'tab' => $tab,
    ])));
}

/**
 * A notification, a mention, and a followed thread, all in one workspace.
 *
 * @return array{0: Workspace, 1: User}
 */
function seedActivity(): array
{
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $mentioned = makeMessage($channel, $owner, 'hey @owner');
    $followed = makeMessage($channel, $owner, 'the question');

    Mention::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => $mentioned->id,
        'mentioned_user_id' => $owner->id,
    ]);

    ThreadSubscription::factory()->create([
        'root_message_id' => $followed->id,
        'user_id' => $owner->id,
    ]);

    Notification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => NotificationReason::Mention->value,
        'notifiable_type' => $owner->getMorphClass(),
        'notifiable_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'data' => [
            'message_id' => $mentioned->id,
            'preview' => 'hey @owner',
            'actor' => ['id' => $owner->id, 'name' => $owner->name],
            'container' => ['type' => 'channel', 'name' => $channel->name],
        ],
    ]);

    return [$workspace, $owner];
}

it('gathers all three lists on the All tab', function () {
    [$workspace, $owner] = seedActivity();

    $this->actingAs($owner);

    visitActivity($workspace)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('activity/index')
            ->where('tab', 'all')
            ->has('notifications', 1)
            ->has('mentions', 1)
            ->has('threads', 1));
});

it('builds only the open tab\'s list', function () {
    [$workspace, $owner] = seedActivity();

    $this->actingAs($owner);

    /*
     * Null rather than an empty array, and the difference is the point: the
     * client renders "nothing waiting" for `[]` and knows not to render the
     * list at all for `null`. It is also the assertion that the other two
     * queries did not run.
     */
    visitActivity($workspace, 'mentions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'mentions')
            ->has('mentions', 1)
            ->where('notifications', null)
            ->where('threads', null));
});

it('serves the notifications tab on its own', function () {
    [$workspace, $owner] = seedActivity();

    $this->actingAs($owner);

    visitActivity($workspace, 'notifications')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'notifications')
            ->has('notifications', 1)
            ->where('mentions', null)
            ->where('threads', null));
});

it('serves the threads tab on its own', function () {
    [$workspace, $owner] = seedActivity();

    $this->actingAs($owner);

    visitActivity($workspace, 'threads')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'threads')
            ->has('threads', 1)
            ->where('notifications', null)
            ->where('mentions', null));
});

it('falls back to All for a tab it does not know', function () {
    [$workspace, $owner] = seedActivity();

    $this->actingAs($owner);

    /*
     * A hand-editable query parameter on a read-only page. Someone who mistypes
     * it should land on the feed that contains everything, not on a 404.
     */
    visitActivity($workspace, 'everything')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tab', 'all'));
});

it('shows one person nothing of another\'s', function () {
    [$workspace] = seedActivity();
    $stranger = joinWorkspace($workspace);

    // The lists are scoped to the viewer, not to the workspace: every row in
    // them exists because it was raised to, or named, one particular person.
    $this->actingAs($stranger);

    visitActivity($workspace)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications', 0)
            ->has('mentions', 0)
            ->has('threads', 0));
});

it('refuses someone who is not in the workspace', function () {
    [$workspace] = seedActivity();

    $this->actingAs(User::factory()->create());

    visitActivity($workspace)->assertNotFound();
});

it('leaves a deleted message out of the notification list', function () {
    [$workspace, $owner] = seedActivity();

    Message::query()->where('body', 'hey @owner')->first()->delete();

    $this->actingAs($owner);

    /*
     * The notification snapshots the body, which is what lets it render after
     * the message moves on — but not after it is withdrawn. The mention row is
     * gone for the same reason, through its own relation.
     */
    visitActivity($workspace)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications', 0)
            ->has('mentions', 0));
});

it('keeps the standalone pages serving the same lists', function () {
    [$workspace, $owner] = seedActivity();

    $this->actingAs($owner);

    /*
     * The three pages still exist — the sidebar links Threads, and the others
     * are bookmarked — and read the same ActivityFeed as the tabs that mirror
     * them. If one of these ever disagrees with its tab, they have grown a
     * second implementation.
     */
    $this->get(route('notifications.index', $workspace))
        ->assertInertia(fn ($page) => $page->component('notifications/index')->has('notifications', 1));

    $this->get(route('mentions.index', $workspace))
        ->assertInertia(fn ($page) => $page->component('mentions/index')->has('mentions', 1));

    $this->get(route('threads.index', $workspace))
        ->assertInertia(fn ($page) => $page->component('threads/index')->has('threads', 1));
});
