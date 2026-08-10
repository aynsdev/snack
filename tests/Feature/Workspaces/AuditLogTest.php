<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;

/**
 * The audit log viewer.
 *
 * Read-only and admin-gated. There is no write route behind it at all — entries
 * are written by the actions that cause them.
 */

/**
 * Write an entry directly, so a test about the *viewer* is not also a test of
 * whichever action happens to produce that action value.
 */
function auditEntry(Workspace $workspace, AuditAction $action, ?User $actor = null): AuditLog
{
    return AuditLog::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $actor?->id,
        'action' => $action,
        'new_values' => ['name' => 'something'],
        'ip_address' => '127.0.0.1',
    ]);
}

it('shows an admin what has happened in this workspace', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    auditEntry($workspace, AuditAction::MemberInvited, $owner);
    auditEntry($workspace, AuditAction::ChannelArchived, $owner);

    $this->actingAs($owner)
        ->get(route('audit.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspace/audit')
            ->has('entries.data', 2)
            ->where('subject', null)
            // Resolved server-side so the client carries no copy of the enum.
            ->where('entries.data.0.action_label', AuditAction::ChannelArchived->label()));
});

it('never shows another workspace\'s entries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    [$elsewhere, $stranger] = makeWorkspaceWithOwner();

    auditEntry($workspace, AuditAction::MemberInvited, $owner);
    auditEntry($elsewhere, AuditAction::MemberRemoved, $stranger);

    $this->actingAs($owner)
        ->get(route('audit.index', ['workspace' => $workspace->slug]))
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

it('filters by subject', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    auditEntry($workspace, AuditAction::MemberInvited, $owner);
    auditEntry($workspace, AuditAction::MemberRemoved, $owner);
    auditEntry($workspace, AuditAction::ChannelCreated, $owner);

    $this->actingAs($owner)
        ->get(route('audit.index', ['workspace' => $workspace->slug, 'subject' => 'member']))
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 2)
            ->where('subject', 'member'));
});

it('ignores a subject that is not a real one', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    auditEntry($workspace, AuditAction::MemberInvited, $owner);

    /*
     * The value reaches a LIKE pattern and arrives in a query string, so it is
     * validated against the enum rather than trusted. An unknown one falls back
     * to "everything" instead of silently matching nothing.
     */
    $this->actingAs($owner)
        ->get(route('audit.index', ['workspace' => $workspace->slug, 'subject' => "member' OR 1=1--"]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('subject', null));
});

it('offers every subject the enum defines, not just the ones already logged', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    // A filter that only appears once something has happened cannot be used to
    // confirm that nothing has.
    $this->actingAs($owner)
        ->get(route('audit.index', ['workspace' => $workspace->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('subjects', [
                'workspace', 'member', 'channel', 'project', 'standup', 'webhook',
                'message', 'asset',
            ]));
});

it('keeps an entry whose actor has been deleted', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    auditEntry($workspace, AuditAction::MemberRemoved, $admin);

    $admin->delete();

    /*
     * `user_id` is nullOnDelete precisely so the trail survives the deletion of
     * the actor — which is exactly when an audit log matters most.
     */
    $this->actingAs($owner)
        ->get(route('audit.index', ['workspace' => $workspace->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.actor', null));
});

it('orders newest first by id rather than by timestamp', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $first = auditEntry($workspace, AuditAction::MemberInvited, $owner);
    $second = auditEntry($workspace, AuditAction::MemberRemoved, $owner);

    // Two entries written in one transaction share a timestamp to the
    // microsecond, and ordering on it would shuffle them between page loads.
    AuditLog::query()->update(['created_at' => now()]);

    $this->actingAs($owner)
        ->get(route('audit.index', ['workspace' => $workspace->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('entries.data.0.id', $second->id)
            ->where('entries.data.1.id', $first->id));
});

it('paginates rather than capping', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    config(['snack.audit.page_size' => 5]);

    foreach (range(1, 12) as $ignored) {
        auditEntry($workspace, AuditAction::MemberInvited, $owner);
    }

    $this->actingAs($owner)
        ->get(route('audit.index', ['workspace' => $workspace->slug]))
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 5)
            ->where('entries.meta.total', 12)
            ->where('entries.meta.last_page', 3));
});

it('keeps the filter across pages', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    config(['snack.audit.page_size' => 2]);

    foreach (range(1, 5) as $ignored) {
        auditEntry($workspace, AuditAction::MemberInvited, $owner);
    }

    $this->actingAs($owner)
        ->get(route('audit.index', ['workspace' => $workspace->slug, 'subject' => 'member', 'page' => 2]))
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 2)
            ->where('entries.meta.current_page', 2)
            ->where('subject', 'member'));
});

it('keeps an ordinary member out', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace, role: WorkspaceRole::Member);

    $this->actingAs($member)
        ->get(route('audit.index', ['workspace' => $workspace->slug]))
        ->assertForbidden();
});

it('keeps a non-member out entirely', function () {
    [$workspace] = makeWorkspaceWithOwner();

    $this->actingAs(User::factory()->create())
        ->get(route('audit.index', ['workspace' => $workspace->slug]))
        ->assertNotFound();
});
