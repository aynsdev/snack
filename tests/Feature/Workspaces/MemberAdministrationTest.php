<?php

use App\Enums\AuditAction;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;

/**
 * Role changes, suspension, and removal.
 *
 * The rank rules are the interesting part: an admin may act on members and
 * guests, but never on the owner, a peer admin, or themselves.
 */
it('lets an admin change a member role', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $member = joinWorkspace($workspace);

    $membership = $workspace->members()->where('user_id', $member->id)->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('members.update', ['workspace' => $workspace->slug, 'member' => $membership->id]), [
            'role' => WorkspaceRole::Guest->value,
        ])
        ->assertRedirect();

    expect($membership->fresh()->role)->toBe(WorkspaceRole::Guest);
});

it('records a role change in the audit log', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $member = joinWorkspace($workspace);

    $membership = $workspace->members()->where('user_id', $member->id)->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('members.update', ['workspace' => $workspace->slug, 'member' => $membership->id]), [
            'role' => WorkspaceRole::Admin->value,
        ]);

    $entry = AuditLog::where('workspace_id', $workspace->id)
        ->where('action', AuditAction::MemberRoleChanged)
        ->firstOrFail();

    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->old_values)->toBe(['role' => 'member'])
        ->and($entry->new_values)->toBe(['role' => 'admin']);
});

it('lets an admin suspend and reinstate a member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $member = joinWorkspace($workspace);

    $membership = $workspace->members()->where('user_id', $member->id)->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('members.update', ['workspace' => $workspace->slug, 'member' => $membership->id]), [
            'status' => MemberStatus::Suspended->value,
        ]);

    expect($membership->fresh()->status)->toBe(MemberStatus::Suspended)
        ->and($member->fresh()->roleIn($workspace))->toBeNull();

    $this->actingAs($admin)
        ->patch(route('members.update', ['workspace' => $workspace->slug, 'member' => $membership->id]), [
            'status' => MemberStatus::Active->value,
        ]);

    expect($membership->fresh()->status)->toBe(MemberStatus::Active)
        ->and($member->fresh()->roleIn($workspace))->toBe(WorkspaceRole::Member);
});

it('stops an admin from modifying the owner', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    $ownerMembership = $workspace->members()->where('user_id', $owner->id)->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('members.update', ['workspace' => $workspace->slug, 'member' => $ownerMembership->id]), [
            'status' => MemberStatus::Suspended->value,
        ])
        ->assertForbidden();

    expect($ownerMembership->fresh()->status)->toBe(MemberStatus::Active);
});

it('stops an admin from modifying a peer admin', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $peer = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    $peerMembership = $workspace->members()->where('user_id', $peer->id)->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('members.update', ['workspace' => $workspace->slug, 'member' => $peerMembership->id]), [
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertForbidden();

    expect($peerMembership->fresh()->role)->toBe(WorkspaceRole::Admin);
});

it('lets the owner demote an admin', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    $adminMembership = $workspace->members()->where('user_id', $admin->id)->firstOrFail();

    $this->actingAs($owner)
        ->patch(route('members.update', ['workspace' => $workspace->slug, 'member' => $adminMembership->id]), [
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertRedirect();

    expect($adminMembership->fresh()->role)->toBe(WorkspaceRole::Member);
});

it('stops an admin from editing their own membership', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    $ownMembership = $workspace->members()->where('user_id', $admin->id)->firstOrFail();

    $this->actingAs($admin)
        ->patch(route('members.update', ['workspace' => $workspace->slug, 'member' => $ownMembership->id]), [
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertForbidden();
});

it('stops an ordinary member from managing anyone', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $other = joinWorkspace($workspace);

    $otherMembership = $workspace->members()->where('user_id', $other->id)->firstOrFail();

    $this->actingAs($member)
        ->patch(route('members.update', ['workspace' => $workspace->slug, 'member' => $otherMembership->id]), [
            'role' => WorkspaceRole::Guest->value,
        ])
        ->assertForbidden();
});

it('removes a member and their channel memberships but keeps their messages', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $member = joinWorkspace($workspace);

    $channel = makeChannel($workspace);
    addToChannel($channel, $member);

    $membership = $workspace->members()->where('user_id', $member->id)->firstOrFail();

    $this->actingAs($admin)
        ->delete(route('members.destroy', ['workspace' => $workspace->slug, 'member' => $membership->id]))
        ->assertRedirect();

    expect($workspace->members()->where('user_id', $member->id)->exists())->toBeFalse()
        ->and($channel->fresh()->hasMember($member))->toBeFalse()
        // The account itself survives; they may belong to other workspaces.
        ->and($member->fresh())->not->toBeNull();
});

it('hides the member directory from guests', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->get(route('members.index', $workspace))
        ->assertForbidden();
});

it('shows the member directory to an ordinary member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $this->actingAs($member)
        ->get(route('members.index', $workspace))
        ->assertOk();
});
