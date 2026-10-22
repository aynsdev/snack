<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The workspace's settings, logo and audit log over the API — admin-gated, as
 * on the web.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->base = "/api/v1/w/{$this->workspace->slug}";
});

it('shows an admin the current settings', function () {
    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/settings")
        ->assertOk()
        ->assertJsonPath('data.workspace.slug', $this->workspace->slug)
        ->assertJsonPath('data.settings.allow_invitations', true)
        ->assertJsonPath('data.member_count', 2)
        ->assertJsonPath('data.is_owner', true);
});

it('refuses the settings to an ordinary member', function () {
    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/settings")
        ->assertForbidden();
});

it('hides the settings from somebody outside the workspace', function () {
    [, $stranger] = makeWorkspaceWithOwner();

    $this->withToken(mobileToken($stranger))
        ->getJson("{$this->base}/settings")
        ->assertNotFound();
});

it('saves the settings and answers with them', function () {
    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/settings", [
            'name' => 'Renamed HQ',
            'description' => 'Where the work happens',
            'member_limit' => 250,
            'allow_invitations' => false,
            'allow_self_serve_joins' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.workspace.name', 'Renamed HQ')
        ->assertJsonPath('data.settings.allow_invitations', false);

    expect($this->workspace->fresh()->member_limit)->toBe(250);
});

it('rejects a member limit below the current headcount', function () {
    $this->withToken(mobileToken($this->owner))
        ->patchJson("{$this->base}/settings", [
            'name' => 'Snack HQ',
            'member_limit' => 1,
            'allow_invitations' => true,
            'allow_self_serve_joins' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('member_limit');
});

it('uploads and removes the logo', function () {
    Storage::fake('public');

    $this->withToken(mobileToken($this->owner))
        ->post("{$this->base}/settings/logo", [
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.slug', $this->workspace->slug);

    $path = $this->workspace->fresh()->logo_path;

    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);

    $this->withToken(mobileToken($this->owner))
        ->deleteJson("{$this->base}/settings/logo")
        ->assertOk();

    expect($this->workspace->fresh()->logo_path)->toBeNull();
});

it('refuses a logo upload from an ordinary member', function () {
    Storage::fake('public');

    $this->withToken(mobileToken($this->member))
        ->post("{$this->base}/settings/logo", [
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
        ], ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('lists the audit log, filtered by subject', function () {
    foreach ([AuditAction::MemberInvited, AuditAction::ChannelArchived] as $action) {
        AuditLog::query()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'action' => $action,
            'new_values' => ['name' => 'something'],
            'ip_address' => '127.0.0.1',
        ]);
    }

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/audit")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', AuditAction::ChannelArchived->value)
        ->assertJsonPath('meta.current_page', 1);

    $this->withToken(mobileToken($this->owner))
        ->getJson("{$this->base}/audit?subject=member")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('subject', 'member');
});

it('refuses the audit log to an ordinary member', function () {
    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/audit")
        ->assertForbidden();
});
