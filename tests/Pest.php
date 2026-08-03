<?php

use App\Enums\ChannelRole;
use App\Enums\ChannelType;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Events\ChannelCreated;
use App\Events\ChannelRead;
use App\Events\ConversationActivity;
use App\Events\ConversationRead;
use App\Events\ConversationStarted;
use App\Events\ConversationUpdated;
use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\PinChanged;
use App\Events\PresenceChanged;
use App\Events\ReactionChanged;
use App\Events\ThreadActivity;
use App\Events\WorkspaceMemberJoined;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Snack's broadcast events are queued and, with the sync queue, would
        // make a real HTTP call to a Reverb server that is not running.
        // Faking just these classes leaves model events and every other
        // listener untouched, and tests can still assert on dispatch with
        // Event::assertDispatched().
        Event::fake([
            ChannelCreated::class,
            ChannelRead::class,
            ConversationActivity::class,
            ConversationRead::class,
            ConversationStarted::class,
            ConversationUpdated::class,
            MessageDeleted::class,
            MessageSent::class,
            MessageUpdated::class,
            PinChanged::class,
            PresenceChanged::class,
            ReactionChanged::class,
            ThreadActivity::class,
            WorkspaceMemberJoined::class,

            /*
             * Laravel's own notification broadcast, faked for the same reason
             * and only for that reason: it would put a real HTTP call to Reverb
             * on the sync queue. The database channel is untouched, so a test
             * can still assert that a notification was *stored* — which is what
             * the notification centre reads — and Event::assertDispatched() on
             * this class covers the live-arrival half.
             */
            BroadcastNotificationCreated::class,
        ]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Snack's authorization rules are all relative to a workspace membership, so
 * nearly every feature test needs a workspace, a member, and a role. These
 * helpers keep that setup to one line, so each test reads as the rule it is
 * asserting rather than as fixture plumbing.
 */

/**
 * Create a workspace owned by a fresh user.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeWorkspace(array $attributes = []): Workspace
{
    return Workspace::factory()->create($attributes);
}

/**
 * Add a user to a workspace with the given role, creating the user if needed.
 */
function joinWorkspace(
    Workspace $workspace,
    ?User $user = null,
    WorkspaceRole $role = WorkspaceRole::Member,
    MemberStatus $status = MemberStatus::Active,
): User {
    $user ??= User::factory()->create();

    WorkspaceMember::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => $status,
    ]);

    // Drop any membership relation loaded earlier in the test so roleIn() sees
    // the row that was just written.
    $user->unsetRelation('workspaceMemberships');

    return $user;
}

/**
 * A workspace whose owner membership already exists, plus that owner.
 *
 * @return array{0: Workspace, 1: User}
 */
function makeWorkspaceWithOwner(): array
{
    $workspace = makeWorkspace();
    $owner = joinWorkspace($workspace, $workspace->owner, WorkspaceRole::Owner);

    return [$workspace, $owner];
}

/**
 * Create a channel in a workspace.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeChannel(
    Workspace $workspace,
    ChannelType $type = ChannelType::Public,
    array $attributes = [],
): Channel {
    return Channel::factory()
        ->forWorkspace($workspace)
        ->create([...$attributes, 'type' => $type]);
}

/**
 * Add a user to a channel.
 */
function addToChannel(Channel $channel, User $user, ChannelRole $role = ChannelRole::Member): ChannelMember
{
    $membership = ChannelMember::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    $channel->unsetRelation('members');

    return $membership;
}

/**
 * Post a message straight into a channel, bypassing the HTTP layer.
 *
 * For tests whose subject is what happens *around* a message — pagination,
 * unread counts, deletion — rather than the act of sending one.
 */
function makeMessage(Channel $channel, User $sender, ?string $body = null): Message
{
    $factory = Message::factory()->inChannel($channel)->from($sender);

    return ($body === null ? $factory : $factory->withBody($body))->create();
}

/**
 * A conversation between the given people, with their participant rows.
 *
 * Two participants produce a direct conversation, three or more a group — the
 * same rule the application applies, so a test never ends up asserting against a
 * shape the product cannot actually create.
 *
 * @param  array<int, User>  $users
 */
function makeConversation(Workspace $workspace, array $users, ?string $name = null): Conversation
{
    $factory = Conversation::factory()->between($users);

    return ($name === null ? $factory : $factory->group($name))
        ->create(['workspace_id' => $workspace->id]);
}

/**
 * Post a message straight into a conversation, bypassing the HTTP layer.
 */
function makeConversationMessage(Conversation $conversation, User $sender, ?string $body = null): Message
{
    $factory = Message::factory()->inConversation($conversation)->from($sender);

    return ($body === null ? $factory : $factory->withBody($body))->create();
}
