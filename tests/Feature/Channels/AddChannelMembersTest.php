<?php

use App\Enums\AuditAction;
use App\Enums\ChannelRole;
use App\Enums\ChannelType;
use App\Enums\MessageType;
use App\Enums\WorkspaceRole;
use App\Events\ChannelMembershipGranted;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * The route for putting *other* people into a channel.
 */
function addMembersTo(Channel $channel): string
{
    return route('channels.members.store', [
        'workspace' => $channel->workspace->slug,
        'channel' => $channel->slug,
    ]);
}

it('lets a workspace admin add someone to a public channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), ['user_ids' => [$member->id]])
        ->assertRedirect();

    expect($channel->fresh()->hasMember($member))->toBeTrue();
});

/**
 * The case self-serve joining cannot cover: a private channel refuses `join`
 * outright, so being added is the only way in.
 */
it('lets a workspace admin add someone to a private channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), ['user_ids' => [$member->id]])
        ->assertRedirect();

    expect($channel->fresh()->hasMember($member))->toBeTrue();
});

it('lets a channel admin who is an ordinary member add people', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $lead = joinWorkspace($workspace);
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    addToChannel($channel, $lead, ChannelRole::Admin);

    $this->actingAs($lead)
        ->post(addMembersTo($channel), ['user_ids' => [$member->id]])
        ->assertRedirect();

    expect($channel->fresh()->hasMember($member))->toBeTrue();
});

it('refuses an ordinary member of the channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $outsider = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    addToChannel($channel, $member);

    $this->actingAs($member)
        ->post(addMembersTo($channel), ['user_ids' => [$outsider->id]])
        ->assertForbidden();

    expect($channel->fresh()->hasMember($outsider))->toBeFalse();
});

/**
 * Somebody who is in the workspace but not in the private channel cannot add
 * people to it. The refusal comes from ChannelPolicy::managementRights, which
 * answers 403 for every non-admin rather than the 404 `view` gives — see the
 * note in that policy.
 */
it('refuses a workspace member who is not in the private channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $target = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($member)
        ->post(addMembersTo($channel), ['user_ids' => [$target->id]])
        ->assertForbidden();

    expect($channel->fresh()->hasMember($target))->toBeFalse();
});

it('adds several people in one request', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $first = joinWorkspace($workspace);
    $second = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), [
            'user_ids' => [$first->id, $second->id],
        ])
        ->assertRedirect();

    $channel = $channel->fresh();

    expect($channel->hasMember($first))->toBeTrue()
        ->and($channel->hasMember($second))->toBeTrue();
});

/**
 * Being added by somebody else is the only route into a channel for a guest —
 * the join policy denies them the self-serve one on purpose.
 */
it('lets an admin add a guest', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), ['user_ids' => [$guest->id]])
        ->assertRedirect();

    expect($channel->fresh()->hasMember($guest))->toBeTrue();
});

it('refuses someone who is not in the workspace', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $outsider = User::factory()->create();
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), ['user_ids' => [$outsider->id]])
        ->assertSessionHasErrors('user_ids.0');

    expect($channel->fresh()->hasMember($outsider))->toBeFalse();
});

it('refuses to add anyone to an archived channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, attributes: ['is_archived' => true]);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), ['user_ids' => [$member->id]])
        ->assertForbidden();

    expect($channel->fresh()->hasMember($member))->toBeFalse();
});

/**
 * Two admins adding the same colleague at once is ordinary, not a conflict, so
 * the second request is a no-op rather than a duplicate row or an error.
 */
it('treats adding an existing member as a no-op', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    addToChannel($channel, $member);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), ['user_ids' => [$member->id]])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($channel->members()->where('user_id', $member->id)->count())->toBe(1)
        ->and(AuditLog::query()->whereAction(AuditAction::ChannelMemberAdded)->count())->toBe(0);
});

it('says in the channel who was added and by whom', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), ['user_ids' => [$member->id]]);

    $system = $channel->messages()->where('type', MessageType::System)->sole();

    expect($system->sender_id)->toBeNull()
        ->and($system->body)->toContain($owner->name)
        ->and($system->body)->toContain($member->name);
});

it('records one audit entry per person added, naming who added them', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $first = joinWorkspace($workspace);
    $second = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), [
            'user_ids' => [$first->id, $second->id],
        ]);

    $entries = AuditLog::query()->whereAction(AuditAction::ChannelMemberAdded)->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('new_values.user_id')->all())
        ->toEqualCanonicalizing([$first->id, $second->id])
        ->and($entries->first()->new_values['added_by'])->toBe($owner->username)
        ->and($entries->first()->user_id)->toBe($owner->id);
});

/**
 * The newcomer is not looking at the channel — they do not know it exists yet —
 * so the only subscription that can reach them is their own user channel.
 */
it('tells the people who were added, and nobody else', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $added = joinWorkspace($workspace);
    $bystander = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    addToChannel($channel, $bystander);

    $this->actingAs($owner)
        ->post(addMembersTo($channel), ['user_ids' => [$added->id]]);

    Event::assertDispatched(
        ChannelMembershipGranted::class,
        fn (ChannelMembershipGranted $event): bool => $event->channel->is($channel)
            && $event->addedUserIds === [$added->id],
    );
});
