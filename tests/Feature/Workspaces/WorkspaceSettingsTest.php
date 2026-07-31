<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The workspace's own details and the two policies that decide who gets in.
 *
 * Everything here is admin-gated and shared, which is the whole distinction
 * from the notification preferences screen at the neighbouring URL.
 */

/**
 * The payload the settings form always submits in full — every field is
 * required, so a partial post is a validation error rather than a save.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function settingsPayload(array $overrides = []): array
{
    return [
        'name' => 'Snack HQ',
        'description' => 'Where the work happens',
        'member_limit' => 500,
        'allow_invitations' => true,
        'allow_self_serve_joins' => true,
        ...$overrides,
    ];
}

it('shows an admin the current settings', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->get(route('workspace-settings.edit', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspace/settings')
            ->where('settings.allow_invitations', true)
            ->where('settings.allow_self_serve_joins', true)
            ->where('member_count', 1)
            ->where('is_owner', true));
});

it('saves the workspace details', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->from(route('workspace-settings.edit', ['workspace' => $workspace->slug]))
        ->patch(route('workspace-settings.update', ['workspace' => $workspace->slug]), settingsPayload([
            'name' => 'Renamed HQ',
            'member_limit' => 250,
        ]))
        ->assertRedirect();

    $fresh = $workspace->fresh();

    expect($fresh->name)->toBe('Renamed HQ')
        ->and($fresh->member_limit)->toBe(250);
});

it('leaves the slug alone when the workspace is renamed', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $slug = $workspace->slug;

    /*
     * Unlike a channel's, this slug is the root of every bookmarked URL, every
     * invitation link already sent, and the tenant key in the session.
     * Regenerating it on a rename would invalidate all three at once.
     */
    $this->actingAs($owner)
        ->patch(route('workspace-settings.update', ['workspace' => $workspace->slug]), settingsPayload([
            'name' => 'A Completely Different Name',
        ]));

    expect($workspace->fresh()->slug)->toBe($slug);
});

it('turns the access policies off', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->patch(route('workspace-settings.update', ['workspace' => $workspace->slug]), settingsPayload([
            'allow_invitations' => false,
            'allow_self_serve_joins' => false,
        ]));

    $fresh = $workspace->fresh();

    expect($fresh->allowsInvitations())->toBeFalse()
        ->and($fresh->allowsSelfServeChannelJoins())->toBeFalse();
});

it('actually stops invitations once they are turned off', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->patch(route('workspace-settings.update', ['workspace' => $workspace->slug]), settingsPayload([
            'allow_invitations' => false,
        ]));

    // The setting is not decoration: WorkspacePolicy::inviteMembers reads it.
    $this->actingAs($owner)
        ->post(route('workspace-invitations.store', ['workspace' => $workspace->slug]), [
            'email' => 'newcomer@example.com',
            'role' => WorkspaceRole::Member->value,
        ])
        ->assertForbidden();
});

it('refuses a member limit below the people already here', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    joinWorkspace($workspace);
    joinWorkspace($workspace);

    /*
     * A limit under the current roster removes nobody — it just makes every
     * future invitation fail, which reads as a bug rather than as the setting
     * somebody typed.
     */
    $this->actingAs($owner)
        ->from(route('workspace-settings.edit', ['workspace' => $workspace->slug]))
        ->patch(route('workspace-settings.update', ['workspace' => $workspace->slug]), settingsPayload([
            'member_limit' => 1,
        ]))
        ->assertSessionHasErrors('member_limit');
});

it('separates a details change from a settings change in the log', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->patch(route('workspace-settings.update', ['workspace' => $workspace->slug]), settingsPayload([
            'name' => 'Renamed HQ',
            'allow_invitations' => false,
        ]));

    /*
     * Renaming a workspace and closing the door on everyone outside are
     * different kinds of event, and an administrator should be able to filter
     * for the second without wading through the first.
     */
    $updated = AuditLog::query()->whereAction(AuditAction::WorkspaceUpdated)->sole();
    $settings = AuditLog::query()->whereAction(AuditAction::WorkspaceSettingsUpdated)->sole();

    expect($updated->old_values['name'])->toBe($workspace->name)
        ->and($updated->new_values['name'])->toBe('Renamed HQ')
        ->and(data_get($settings->new_values, 'auth.allow_invitations'))->toBeFalse();
});

it('writes nothing to the log when nothing changed', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->patch(route('workspace-settings.update', ['workspace' => $workspace->slug]), settingsPayload([
            'name' => $workspace->name,
            'description' => $workspace->description,
            'member_limit' => $workspace->member_limit,
        ]));

    expect(AuditLog::query()->whereAction(AuditAction::WorkspaceUpdated)->count())->toBe(0);
});

it('keeps an ordinary member out of the settings screen', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace, role: WorkspaceRole::Member);

    $this->actingAs($member)
        ->get(route('workspace-settings.edit', ['workspace' => $workspace->slug]))
        ->assertForbidden();

    $this->actingAs($member)
        ->patch(route('workspace-settings.update', ['workspace' => $workspace->slug]), settingsPayload())
        ->assertForbidden();
});

it('keeps a non-member out entirely', function () {
    [$workspace] = makeWorkspaceWithOwner();

    // ResolveWorkspace answers 404 before the policy is ever consulted.
    $this->actingAs(User::factory()->create())
        ->get(route('workspace-settings.edit', ['workspace' => $workspace->slug]))
        ->assertNotFound();
});

it('stores a re-encoded logo and logs the change', function () {
    Storage::fake('public');

    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)
        ->from(route('workspace-settings.edit', ['workspace' => $workspace->slug]))
        ->post(route('workspace-logo.store', ['workspace' => $workspace->slug]), [
            'logo' => UploadedFile::fake()->image('logo.jpg', 900, 600),
        ])
        ->assertRedirect();

    $path = $workspace->fresh()->logo_path;

    expect($path)->not->toBeNull();

    Storage::disk('public')->assertExists($path);

    expect(AuditLog::query()->whereAction(AuditAction::WorkspaceUpdated)->count())->toBe(1);
});

it('removes the logo and deletes the file behind it', function () {
    Storage::fake('public');

    [$workspace, $owner] = makeWorkspaceWithOwner();

    $this->actingAs($owner)->post(route('workspace-logo.store', ['workspace' => $workspace->slug]), [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ]);

    $path = $workspace->fresh()->logo_path;

    $this->actingAs($owner)
        ->from(route('workspace-settings.edit', ['workspace' => $workspace->slug]))
        ->delete(route('workspace-logo.destroy', ['workspace' => $workspace->slug]))
        ->assertRedirect();

    expect($workspace->fresh()->logo_path)->toBeNull();

    Storage::disk('public')->assertMissing($path);
});

it('rejects a file that is not an image', function () {
    Storage::fake('public');

    [$workspace, $owner] = makeWorkspaceWithOwner();

    /*
     * File::image() checks the detected type rather than the extension, and
     * excludes SVG — which is a document that can carry script, rendered inline
     * on this application's origin. The re-encode in the action is the real
     * backstop.
     */
    $this->actingAs($owner)
        ->from(route('workspace-settings.edit', ['workspace' => $workspace->slug]))
        ->post(route('workspace-logo.store', ['workspace' => $workspace->slug]), [
            'logo' => UploadedFile::fake()->create('payload.svg', 8, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors('logo');

    expect($workspace->fresh()->logo_path)->toBeNull();
});

it('refuses a logo upload from an ordinary member', function () {
    Storage::fake('public');

    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace, role: WorkspaceRole::Member);

    $this->actingAs($member)
        ->post(route('workspace-logo.store', ['workspace' => $workspace->slug]), [
            'logo' => UploadedFile::fake()->image('logo.jpg'),
        ])
        ->assertForbidden();
});
