<?php

use App\Enums\ChannelRole;
use App\Enums\ChannelType;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Events\ChannelCreated;
use App\Events\WorkspaceMemberJoined;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Event::fake([ChannelCreated::class, WorkspaceMemberJoined::class]);
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
