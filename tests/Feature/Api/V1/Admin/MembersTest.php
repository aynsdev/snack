<?php

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\Notification;

/**
 * The member directory, emailed invitations and the shareable invite link.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->membership = $this->workspace->members()->where('user_id', $this->member->id)->firstOrFail();
    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('lists the members, paged', function () {
    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/members")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('can_manage', false)
        ->assertJsonPath('meta.total', 3);
});

it('lets an admin change a role and suspend a member', function () {
    $this->withToken(mobileToken($this->admin))
        ->patchJson("{$this->base}/members/{$this->membership->id}", ['role' => WorkspaceRole::Guest->value])
        ->assertOk()
        ->assertJsonPath('data.role', WorkspaceRole::Guest->value);

    $this->withToken(mobileToken($this->admin))
        ->patchJson("{$this->base}/members/{$this->membership->id}", ['status' => MemberStatus::Suspended->value])
        ->assertOk()
        ->assertJsonPath('data.status', MemberStatus::Suspended->value);
});

it('stops an admin from modifying the owner', function () {
    $ownerMembership = $this->workspace->members()->where('user_id', $this->owner->id)->firstOrFail();

    $this->withToken(mobileToken($this->admin))
        ->patchJson("{$this->base}/members/{$ownerMembership->id}", ['role' => WorkspaceRole::Member->value])
        ->assertForbidden();
});

it('removes a member', function () {
    $this->withToken(mobileToken($this->admin))
        ->deleteJson("{$this->base}/members/{$this->membership->id}")
        ->assertNoContent();

    expect($this->member->fresh()->roleIn($this->workspace))->toBeNull();
});

it('refuses removal to an ordinary member', function () {
    $adminMembership = $this->workspace->members()->where('user_id', $this->admin->id)->firstOrFail();

    $this->withToken(mobileToken($this->member))
        ->deleteJson("{$this->base}/members/{$adminMembership->id}")
        ->assertForbidden();
});

it('invites someone by email and lists the invitation', function () {
    Notification::fake();

    $this->withToken(mobileToken($this->admin))
        ->postJson("{$this->base}/invitations", ['email' => 'new@example.com', 'role' => WorkspaceRole::Member->value])
        ->assertCreated()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonMissingPath('data.url');

    $this->withToken(mobileToken($this->admin))
        ->getJson("{$this->base}/invitations")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('invite_link', null);
});

it('stops an ordinary member from inviting people', function () {
    $this->withToken(mobileToken($this->member))
        ->postJson("{$this->base}/invitations", ['email' => 'new@example.com', 'role' => WorkspaceRole::Member->value])
        ->assertForbidden();
});

it('revokes an invitation', function () {
    Notification::fake();

    $this->withToken(mobileToken($this->admin))
        ->postJson("{$this->base}/invitations", ['email' => 'new@example.com', 'role' => WorkspaceRole::Member->value]);

    $invitation = WorkspaceInvitation::query()->sole();

    $this->withToken(mobileToken($this->admin))
        ->deleteJson("{$this->base}/invitations/{$invitation->id}")
        ->assertNoContent();

    expect($invitation->fresh()->revoked_at)->not->toBeNull();
});

it('creates the invite link with its URL, and turns it off', function () {
    $url = $this->withToken(mobileToken($this->admin))
        ->postJson("{$this->base}/invite-link", ['role' => WorkspaceRole::Member->value, 'max_uses' => 10])
        ->assertCreated()
        ->assertJsonPath('data.is_link', true)
        ->assertJsonPath('data.max_uses', 10)
        ->json('data.url');

    expect($url)->toBeString()->not->toBeEmpty();

    $this->withToken(mobileToken($this->admin))
        ->getJson("{$this->base}/invitations")
        ->assertJsonPath('invite_link.url', $url);

    $this->withToken(mobileToken($this->admin))
        ->deleteJson("{$this->base}/invite-link")
        ->assertNoContent();

    // Already off is the state asked for.
    $this->withToken(mobileToken($this->admin))
        ->deleteJson("{$this->base}/invite-link")
        ->assertNoContent();

    expect(WorkspaceInvitation::query()->links()->outstanding()->exists())->toBeFalse();
});
