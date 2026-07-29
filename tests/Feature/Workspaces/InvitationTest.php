<?php

use App\Actions\Workspaces\AcceptWorkspaceInvitation;
use App\Actions\Workspaces\InviteWorkspaceMember;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Invitation issuing, redemption, expiry, and revocation.
 *
 * The token in the URL is never stored; only its SHA-256 hash is. These tests
 * therefore go through the plaintext exposed by the factory, exactly as a
 * recipient would go through the link in their email.
 */
it('stores only a hash of the invitation token', function () {
    $invitation = WorkspaceInvitation::factory()->create();
    $plainToken = $invitation->plainToken;

    expect($invitation->token)->not->toBe($plainToken)
        ->and($invitation->token)->toBe(hash('sha256', $plainToken))
        ->and(WorkspaceInvitation::findByToken($plainToken)?->id)->toBe($invitation->id);
});

it('lets an admin invite someone and emails them a link', function () {
    Notification::fake();

    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->post(route('workspace-invitations.store', $workspace), [
            'email' => 'newcomer@snack.test',
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertRedirect();

    expect($workspace->invitations()->where('email', 'newcomer@snack.test')->exists())->toBeTrue();

    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class);
});

it('stops an ordinary member from inviting people', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    $this->actingAs($member)
        ->post(route('workspace-invitations.store', $workspace), [
            'email' => 'newcomer@snack.test',
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertForbidden();
});

it('refuses to invite someone who is already a member', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $existing = joinWorkspace($workspace);

    $this->actingAs($admin)
        ->post(route('workspace-invitations.store', $workspace), [
            'email' => $existing->email,
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertSessionHasErrors('email');
});

it('never issues an owner invitation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->post(route('workspace-invitations.store', $workspace), [
            'email' => 'usurper@snack.test',
            'role' => WorkspaceRole::Owner->value,
        ])
        ->assertSessionHasErrors('role');
});

it('adds the invited user to the workspace and its default channels', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $defaultChannel = makeChannel($workspace, attributes: ['is_default' => true]);
    $ordinaryChannel = makeChannel($workspace);

    $user = User::factory()->create();
    $invitation = WorkspaceInvitation::factory()
        ->forEmail($user->email)
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    app(AcceptWorkspaceInvitation::class)->handle($invitation, $user);

    expect($user->fresh()->roleIn($workspace))->toBe(WorkspaceRole::Member)
        ->and($defaultChannel->fresh()->hasMember($user))->toBeTrue()
        // Only default channels are auto-joined.
        ->and($ordinaryChannel->fresh()->hasMember($user))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('does not auto-join a guest to default channels', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $defaultChannel = makeChannel($workspace, attributes: ['is_default' => true]);

    $user = User::factory()->create();
    $invitation = WorkspaceInvitation::factory()
        ->forEmail($user->email)
        ->role(WorkspaceRole::Guest)
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    app(AcceptWorkspaceInvitation::class)->handle($invitation, $user);

    expect($user->fresh()->roleIn($workspace))->toBe(WorkspaceRole::Guest)
        ->and($defaultChannel->fresh()->hasMember($user))->toBeFalse();
});

it('refuses an expired invitation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $user = User::factory()->create();

    $invitation = WorkspaceInvitation::factory()
        ->forEmail($user->email)
        ->expired()
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    expect($invitation->isPending())->toBeFalse();

    expect(fn () => app(AcceptWorkspaceInvitation::class)->handle($invitation, $user))
        ->toThrow(ValidationException::class);

    expect($user->fresh()->belongsToWorkspace($workspace))->toBeFalse();
});

it('refuses a revoked invitation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $user = User::factory()->create();

    $invitation = WorkspaceInvitation::factory()
        ->forEmail($user->email)
        ->revoked()
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    expect(fn () => app(AcceptWorkspaceInvitation::class)->handle($invitation, $user))
        ->toThrow(ValidationException::class);
});

it('cannot redeem the same invitation twice', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $user = User::factory()->create();

    $invitation = WorkspaceInvitation::factory()
        ->forEmail($user->email)
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    app(AcceptWorkspaceInvitation::class)->handle($invitation, $user);

    expect(fn () => app(AcceptWorkspaceInvitation::class)->handle($invitation->fresh(), $user))
        ->toThrow(ValidationException::class);

    expect($workspace->members()->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses an invitation addressed to someone else', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $wrongPerson = User::factory()->create();

    $invitation = WorkspaceInvitation::factory()
        ->forEmail('intended@snack.test')
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    $this->actingAs($wrongPerson)
        ->post(route('invitations.accept', ['token' => $invitation->plainToken]))
        ->assertForbidden();

    expect($wrongPerson->fresh()->belongsToWorkspace($workspace))->toBeFalse();
});

it('accepts an invitation through the web route', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $user = User::factory()->create();

    $invitation = WorkspaceInvitation::factory()
        ->forEmail($user->email)
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    $this->actingAs($user)
        ->post(route('invitations.accept', ['token' => $invitation->plainToken]))
        ->assertRedirect(route('workspaces.show', $workspace));

    expect($user->fresh()->belongsToWorkspace($workspace))->toBeTrue();
});

it('shows an invalid-invitation page for an unknown token', function () {
    $this->get(route('invitations.show', ['token' => 'not-a-real-token']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/invitation-invalid'));
});

it('lets an admin revoke an outstanding invitation', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    $invitation = WorkspaceInvitation::factory()
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $admin->id]);

    $this->actingAs($admin)
        ->delete(route('workspace-invitations.destroy', [
            'workspace' => $workspace->slug,
            'invitation' => $invitation->id,
        ]))
        ->assertRedirect();

    expect($invitation->fresh()->isRevoked())->toBeTrue()
        ->and($invitation->fresh()->isPending())->toBeFalse();
});

it('replaces an earlier invitation when the same address is re-invited', function () {
    Notification::fake();

    [$workspace, $owner] = makeWorkspaceWithOwner();
    $action = app(InviteWorkspaceMember::class);

    $first = $action->handle($workspace, $owner, 'twice@snack.test');
    $second = $action->handle($workspace, $owner, 'twice@snack.test');

    expect($first->fresh()->isRevoked())->toBeTrue()
        ->and($second->fresh()->isPending())->toBeTrue();
});
