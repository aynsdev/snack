<?php

use App\Actions\Workspaces\AcceptWorkspaceInvitation;
use App\Actions\Workspaces\CreateWorkspaceInviteLink;
use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\RegistrationGate;
use Illuminate\Validation\ValidationException;

/**
 * The shareable invite link.
 *
 * A link is the same row as an emailed invitation with `email` left null, which
 * is what lets it reuse the token scheme, the landing page, and revocation. The
 * two places it must behave *differently* are what this file pins down: it is
 * addressed to nobody, and it survives being redeemed.
 */
function createLink(
    $workspace,
    User $creator,
    WorkspaceRole $role = WorkspaceRole::Member,
    ?int $maxUses = null,
): WorkspaceInvitation {
    return app(CreateWorkspaceInviteLink::class)->handle($workspace, $creator, $role, $maxUses);
}

it('issues a link that names nobody', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $link = createLink($workspace, $owner);

    expect($link->email)->toBeNull()
        ->and($link->isLink())->toBeTrue()
        ->and($link->isPending())->toBeTrue()
        // The lookup column stays a hash, exactly as for an emailed invitation.
        ->and($link->token)->toBe(hash('sha256', $link->plainToken))
        ->and(WorkspaceInvitation::findByToken($link->plainToken)?->id)->toBe($link->id);
});

it('keeps the link copyable later, encrypted at rest', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $link = createLink($workspace, $owner);
    $plain = $link->plainToken;

    // Re-read from the database, as the admin screen does on a later visit.
    $reloaded = WorkspaceInvitation::query()->findOrFail($link->id);

    expect($reloaded->link_token)->toBe($plain)
        ->and($reloaded->shareableUrl())->toBe(route('invitations.show', ['token' => $plain]));

    /*
     * The stored bytes are ciphertext. A link has to stay retrievable — an
     * admin screen is its only delivery mechanism — but a database leak without
     * APP_KEY still must not be replayable into workspace access.
     */
    $raw = DB::table('workspace_invitations')->where('id', $link->id)->value('link_token');

    expect($raw)->not->toBe($plain);
});

it('lets anybody holding the link join, whatever their address', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $link = createLink($workspace, $owner);

    $stranger = User::factory()->create(['email' => 'nobody-was-invited@example.test']);

    // The whole point: a forwarded link is the feature, not the attack.
    expect($link->isAddressedTo($stranger))->toBeTrue();

    $membership = app(AcceptWorkspaceInvitation::class)->handle($link, $stranger);

    expect($membership->workspace_id)->toBe($workspace->id)
        ->and($membership->role)->toBe(WorkspaceRole::Member);
});

it('survives being redeemed, unlike an emailed invitation', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $link = createLink($workspace, $owner);

    app(AcceptWorkspaceInvitation::class)->handle($link, User::factory()->create());
    app(AcceptWorkspaceInvitation::class)->handle($link, User::factory()->create());

    $link->refresh();

    /*
     * `accepted_at` is what makes an invitation single-use, so a link must never
     * set it — doing so would burn the whole link on the first person through.
     */
    expect($link->accepted_at)->toBeNull()
        ->and($link->use_count)->toBe(2)
        ->and($link->isPending())->toBeTrue();
});

it('stops accepting once the use limit is reached', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $link = createLink($workspace, $owner, maxUses: 2);

    app(AcceptWorkspaceInvitation::class)->handle($link, User::factory()->create());
    app(AcceptWorkspaceInvitation::class)->handle($link, User::factory()->create());

    expect(fn () => app(AcceptWorkspaceInvitation::class)
        ->handle($link->fresh(), User::factory()->create()))
        ->toThrow(ValidationException::class);

    $link->refresh();

    expect($link->use_count)->toBe(2)
        ->and($link->isPending())->toBeFalse()
        ->and($link->status())->toBe('used_up');
});

it('grants the role the link was created with', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $link = createLink($workspace, $owner, role: WorkspaceRole::Admin);

    $membership = app(AcceptWorkspaceInvitation::class)
        ->handle($link, User::factory()->create());

    expect($membership->role)->toBe(WorkspaceRole::Admin);
});

it('replaces the previous link when a new one is issued', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $first = createLink($workspace, $owner);
    $second = createLink($workspace, $owner);

    $first->refresh();

    /*
     * Regenerating is the safety control an admin reaches for when they think a
     * link has leaked, so the old URL has to be dead the moment the new one
     * exists — not merely superseded.
     */
    expect($first->isRevoked())->toBeTrue()
        ->and($first->isPending())->toBeFalse()
        ->and($second->isPending())->toBeTrue()
        ->and($workspace->invitations()->links()->outstanding()->count())->toBe(1);
});

it('leaves emailed invitations alone when the link is regenerated', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $emailed = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
        'email' => 'someone@snack.test',
    ]);

    createLink($workspace, $owner);
    createLink($workspace, $owner);

    // The two share a table; revoking one kind must not touch the other.
    expect($emailed->fresh()->isPending())->toBeTrue();
});

describe('the admin screen', function () {
    it('lets an admin create a link over HTTP', function () {
        [$workspace] = makeWorkspaceWithOwner();
        $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

        $this->actingAs($admin)
            ->post(route('workspace-invite-link.store', $workspace), [
                'role' => WorkspaceRole::Member->value,
                'max_uses' => 5,
            ])
            ->assertRedirect();

        $link = $workspace->invitations()->links()->outstanding()->sole();

        expect($link->max_uses)->toBe(5)
            ->and($link->role)->toBe(WorkspaceRole::Member);
    });

    it('refuses a guest link', function () {
        [$workspace] = makeWorkspaceWithOwner();
        $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

        /*
         * A guest is auto-joined to no channels, so one arriving unannounced
         * through a shared URL lands in an empty workspace with nobody
         * expecting them.
         */
        $this->actingAs($admin)
            ->post(route('workspace-invite-link.store', $workspace), [
                'role' => WorkspaceRole::Guest->value,
            ])
            ->assertInvalid('role');
    });

    it('stops an ordinary member from creating one', function () {
        [$workspace] = makeWorkspaceWithOwner();
        $member = joinWorkspace($workspace);

        // Issuing a link *is* inviting; a weaker gate here would be the way
        // around the stronger one on emailed invitations.
        $this->actingAs($member)
            ->post(route('workspace-invite-link.store', $workspace))
            ->assertForbidden();
    });

    it('turns the link off', function () {
        [$workspace, $owner] = makeWorkspaceWithOwner();
        $link = createLink($workspace, $owner);

        $this->actingAs($owner)
            ->delete(route('workspace-invite-link.destroy', $workspace))
            ->assertRedirect();

        expect($link->fresh()->isRevoked())->toBeTrue();
    });

    it('treats turning off an absent link as already done', function () {
        [$workspace, $owner] = makeWorkspaceWithOwner();

        // Two admins clicking at once, or a stale tab. The desired state is the
        // state, so this is not an error.
        $this->actingAs($owner)
            ->delete(route('workspace-invite-link.destroy', $workspace))
            ->assertRedirect();
    });

    it('shows the link on its own, not among the emailed invitations', function () {
        [$workspace, $owner] = makeWorkspaceWithOwner();

        WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
            'email' => 'someone@snack.test',
        ]);

        $link = createLink($workspace, $owner);

        $props = $this->actingAs($owner)
            ->get(route('workspace-invitations.index', $workspace))
            ->inertiaProps();

        $emails = array_column($props['invitations']['data'], 'email');

        expect($emails)->toBe(['someone@snack.test'])
            ->and($props['inviteLink']['is_link'])->toBeTrue()
            ->and($props['inviteLink']['url'])
            ->toBe(route('invitations.show', ['token' => $link->plainToken]));
    });

    it('never serializes a URL for an emailed invitation', function () {
        [$workspace, $owner] = makeWorkspaceWithOwner();

        WorkspaceInvitation::factory()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
            'email' => 'someone@snack.test',
        ]);

        $props = $this->actingAs($owner)
            ->get(route('workspace-invitations.index', $workspace))
            ->inertiaProps();

        // Its plaintext lives only in the message that carried it, by design.
        expect($props['invitations']['data'][0])->not->toHaveKey('url');
    });
});

describe('the landing page', function () {
    it('offers to join without naming an address', function () {
        [$workspace, $owner] = makeWorkspaceWithOwner();
        $link = createLink($workspace, $owner);

        $props = $this->actingAs(User::factory()->create())
            ->get(route('invitations.show', ['token' => $link->plainToken]))
            ->inertiaProps();

        expect($props['isLink'])->toBeTrue()
            ->and($props['email'])->toBeNull()
            // Nobody is the wrong person for a link, so the switch-account
            // notice must not appear.
            ->and($props['emailMatches'])->toBeTrue();
    });

    it('refuses a revoked link', function () {
        [$workspace, $owner] = makeWorkspaceWithOwner();
        $link = createLink($workspace, $owner);
        $token = $link->plainToken;

        // Regenerating revokes the old one.
        createLink($workspace, $owner);

        $this->actingAs(User::factory()->create())
            ->post(route('invitations.accept', ['token' => $token]))
            ->assertForbidden();
    });

    it('lets someone register through a link with any address', function () {
        [$workspace, $owner] = makeWorkspaceWithOwner();
        $link = createLink($workspace, $owner);

        // Opening the page stashes the token, which is what opens registration.
        $this->get(route('invitations.show', ['token' => $link->plainToken]))
            ->assertOk();

        /*
         * An emailed invitation binds registration to its address. A link has
         * none, so `RegistrationGate::requiredEmail()` returns null and any
         * address is accepted — the null guard that makes this work already
         * existed in CreateNewUser.
         */
        expect(app(RegistrationGate::class)->isOpen())->toBeTrue()
            ->and(app(RegistrationGate::class)->requiredEmail())->toBeNull();
    });
});

it('records link creation and revocation distinctly in the audit log', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    createLink($workspace, $owner);

    $this->actingAs($owner)->delete(route('workspace-invite-link.destroy', $workspace));

    /*
     * Mapped to values rather than compared as enums: `action` is cast, so
     * `pluck` hands back AuditAction instances and a string comparison silently
     * fails against every one of them.
     */
    $actions = $workspace->auditLogs()
        ->pluck('action')
        ->map(fn (AuditAction $action): string => $action->value)
        ->all();

    // An admin reading the log needs to tell "a door closed for everyone
    // holding a URL" from "one person's invitation was withdrawn".
    expect($actions)->toContain(AuditAction::InviteLinkCreated->value)
        ->and($actions)->toContain(AuditAction::InviteLinkRevoked->value)
        ->and($actions)->not->toContain(AuditAction::MemberInvitationRevoked->value);
});
