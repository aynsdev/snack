<?php

use App\Enums\AuditAction;
use App\Enums\ChannelRole;
use App\Enums\ChannelType;
use App\Enums\MessageType;
use App\Events\ChannelMembershipRevoked;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\ChannelMember;
use Illuminate\Support\Facades\Event;

function removeChannelMemberUrl(Channel $channel, ChannelMember $membership): string
{
    return route('channels.members.destroy', [
        'workspace' => $channel->workspace->slug,
        'channel' => $channel->slug,
        'member' => $membership->id,
    ]);
}

it('lets a workspace admin remove someone from a channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);
    $membership = addToChannel($channel, $member);

    $this->actingAs($owner)
        ->delete(removeChannelMemberUrl($channel, $membership))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($channel->fresh()->hasMember($member))->toBeFalse()
        ->and($workspace->fresh()->hasActiveMember($member))->toBeTrue();

    $system = $channel->messages()->where('type', MessageType::System)->sole();
    $audit = AuditLog::query()->whereAction(AuditAction::ChannelMemberRemoved)->sole();

    expect($system->body)->toContain($owner->name)
        ->toContain($member->name)
        ->and($audit->user_id)->toBe($owner->id)
        ->and($audit->old_values['user_id'])->toBe($member->id)
        ->and($audit->old_values['removed_by'])->toBe($owner->username);

    Event::assertDispatched(
        ChannelMembershipRevoked::class,
        fn (ChannelMembershipRevoked $event): bool => $event->channel->is($channel)
            && $event->removedUserId === $member->id,
    );
});

it('lets a channel admin remove another member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $lead = joinWorkspace($workspace);
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);

    addToChannel($channel, $lead, ChannelRole::Admin);
    $membership = addToChannel($channel, $member);

    $this->actingAs($lead)
        ->delete(removeChannelMemberUrl($channel, $membership))
        ->assertRedirect();

    expect($channel->fresh()->hasMember($member))->toBeFalse();
});

it('refuses an ordinary channel member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $actor = joinWorkspace($workspace);
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);

    addToChannel($channel, $actor);
    $membership = addToChannel($channel, $member);

    $this->actingAs($actor)
        ->delete(removeChannelMemberUrl($channel, $membership))
        ->assertForbidden();

    expect($channel->fresh()->hasMember($member))->toBeTrue();
});

it('does not use the admin endpoint to remove yourself', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace, ChannelType::Private);
    $membership = addToChannel($channel, $owner, ChannelRole::Admin);

    $this->actingAs($owner)
        ->delete(removeChannelMemberUrl($channel, $membership))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($channel->fresh()->hasMember($owner))->toBeTrue();
});

it('keeps every workspace member in the default channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace, attributes: ['is_default' => true]);
    $membership = addToChannel($channel, $member);

    $this->actingAs($owner)
        ->delete(removeChannelMemberUrl($channel, $membership))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($channel->fresh()->hasMember($member))->toBeTrue();
});

it('does not accept a membership belonging to another channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    $otherChannel = makeChannel($workspace, attributes: ['name' => 'Other']);
    $otherMembership = addToChannel($otherChannel, $member);

    $this->actingAs($owner)
        ->delete(removeChannelMemberUrl($channel, $otherMembership))
        ->assertNotFound();

    expect($otherChannel->fresh()->hasMember($member))->toBeTrue();
});
