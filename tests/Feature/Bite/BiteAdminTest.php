<?php

use App\Enums\AuditAction;
use App\Enums\BiteAudience;
use App\Enums\BiteCapability;
use App\Enums\BiteChannelMode;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\BiteMemory;
use App\Models\BiteSetting;
use App\Models\Workspace;

/**
 * The screen an admin configures Bite from.
 *
 * Two things are being protected. Configuring an assistant is an administrator's
 * right and reading the page is the same right — the knowledge base is a
 * document the workspace has written, not a list of its rooms. And every
 * identifier the form sends is re-resolved against this workspace, because the
 * guard it writes is read much later on a queue worker, where there is nothing
 * left to check it against.
 */
beforeEach(function () {
    config()->set('snack.bite.enabled', true);
    config()->set('ai.providers.mistral.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
});

function biteSettingsUrl(?Workspace $workspace = null): string
{
    return route('bite-settings.edit', [
        'workspace' => ($workspace ?? test()->workspace)->slug,
    ]);
}

function saveBiteSettings(array $overrides = []): array
{
    return [
        'is_enabled' => true,
        'audience' => BiteAudience::Everyone->value,
        'channel_mode' => BiteChannelMode::All->value,
        'channel_ids' => [],
        'instructions' => null,
        'context_messages' => null,
        'max_steps' => null,
        'capabilities' => BiteCapability::allValues(),
        ...$overrides,
    ];
}

// -- Who may look ----------------------------------------------------------

it('shows the screen to an admin', function () {
    $this->actingAs($this->admin)
        ->get(biteSettingsUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspace/bite')
            ->where('is_configured', false)
            ->where('settings.audience', 'everyone'));
});

/**
 * Reading is the same right as writing here, unlike most screens — the page
 * prints the house instructions and every fact the workspace has written down.
 */
it('refuses an ordinary member', function () {
    $this->actingAs($this->member)
        ->get(biteSettingsUrl())
        ->assertForbidden();
});

/**
 * Somebody with no standing in this workspace does not get as far as the
 * policy: the workspace middleware answers 404, because whether a tenant exists
 * is not something an outsider is told.
 */
it('hides the screen from somebody outside the workspace', function () {
    [$other, $stranger] = makeWorkspaceWithOwner();

    $this->actingAs($stranger)
        ->get(biteSettingsUrl())
        ->assertNotFound();

    expect($other->id)->not->toBe($this->workspace->id);
});

it('says whether the installation has Bite switched on at all', function () {
    config()->set('snack.bite.enabled', false);

    $this->actingAs($this->admin)
        ->get(biteSettingsUrl())
        ->assertInertia(fn ($page) => $page->where('is_available', false));
});

// -- Saving ----------------------------------------------------------------

it('creates the row on the first save', function () {
    expect(BiteSetting::query()->count())->toBe(0);

    $this->actingAs($this->admin)
        ->patch(biteSettingsUrl(), saveBiteSettings([
            'audience' => BiteAudience::Members->value,
            'instructions' => 'Answer in British English.',
        ]))
        ->assertRedirect();

    $settings = BiteSetting::forWorkspace($this->workspace->fresh());

    expect($settings->exists)->toBeTrue()
        ->and($settings->audience)->toBe(BiteAudience::Members)
        ->and($settings->instructions)->toBe('Answer in British English.')
        ->and($settings->updated_by)->toBe($this->admin->id);
});

it('records what changed in the audit log', function () {
    $this->actingAs($this->admin)
        ->patch(biteSettingsUrl(), saveBiteSettings(['is_enabled' => false]));

    $entry = AuditLog::query()->latest('id')->first();

    expect($entry->action)->toBe(AuditAction::BiteSettingsUpdated)
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->new_values['is_enabled'])->toBeFalse();
});

/**
 * Opening the page and pressing Save changes nothing, and must therefore log
 * nothing. An audit trail whose entries are mostly identical old and new values
 * is one nobody reads.
 */
it('writes no audit entry when nothing actually changed', function () {
    BiteSetting::factory()->forWorkspace($this->workspace)->create();

    $before = AuditLog::query()->count();

    $this->actingAs($this->admin)->patch(biteSettingsUrl(), saveBiteSettings());

    expect(AuditLog::query()->count())->toBe($before);
});

it('stores the channel guard', function () {
    $channel = makeChannel($this->workspace);

    $this->actingAs($this->admin)
        ->patch(biteSettingsUrl(), saveBiteSettings([
            'channel_mode' => BiteChannelMode::Allow->value,
            'channel_ids' => [$channel->id],
        ]))
        ->assertRedirect();

    expect(BiteSetting::forWorkspace($this->workspace->fresh())->guardedChannelIds())
        ->toBe([$channel->id]);
});

/**
 * The guard is read on a queue worker later, with no request and no tenant
 * context — so a channel from another workspace has to be refused here, at the
 * one moment there is still something to check it against.
 */
it('refuses a channel from another workspace', function () {
    [$other] = makeWorkspaceWithOwner();
    $theirs = makeChannel($other);

    $this->actingAs($this->admin)
        ->patch(biteSettingsUrl(), saveBiteSettings([
            'channel_mode' => BiteChannelMode::Allow->value,
            'channel_ids' => [$theirs->id],
        ]))
        ->assertSessionHasErrors('channel_ids.0');
});

it('refuses a capability it does not have', function () {
    $this->actingAs($this->admin)
        ->patch(biteSettingsUrl(), saveBiteSettings([
            'capabilities' => ['read_file', 'launch_the_missiles'],
        ]))
        ->assertSessionHasErrors('capabilities.1');
});

it('accepts an empty capability list as a real answer', function () {
    $this->actingAs($this->admin)
        ->patch(biteSettingsUrl(), saveBiteSettings(['capabilities' => []]))
        ->assertRedirect();

    expect(BiteSetting::forWorkspace($this->workspace->fresh())->enabledCapabilities())->toBe([]);
});

it('holds the instructions to the configured limit', function () {
    config()->set('snack.bite.max_instruction_characters', 50);

    $this->actingAs($this->admin)
        ->patch(biteSettingsUrl(), saveBiteSettings([
            'instructions' => str_repeat('a', 51),
        ]))
        ->assertSessionHasErrors('instructions');
});

it('refuses a member trying to save', function () {
    $this->actingAs($this->member)
        ->patch(biteSettingsUrl(), saveBiteSettings())
        ->assertForbidden();

    expect(BiteSetting::query()->count())->toBe(0);
});

// -- The knowledge base ----------------------------------------------------

it('adds a fact', function () {
    $this->actingAs($this->admin)
        ->post(route('bite-memories.store', ['workspace' => $this->workspace->slug]), [
            'title' => 'Release process',
            'body' => 'We ship on Thursdays.',
        ])
        ->assertRedirect();

    $memory = BiteMemory::query()->latest('id')->first();

    expect($memory->title)->toBe('Release process')
        ->and($memory->workspace_id)->toBe($this->workspace->id)
        ->and($memory->created_by)->toBe($this->admin->id)
        ->and($memory->is_enabled)->toBeTrue();

    expect(AuditLog::query()->latest('id')->first()->action)
        ->toBe(AuditAction::BiteMemoryCreated);
});

/**
 * The toggle in the list sends one field. It must not blank the body it did not
 * send — which is the whole reason the update request validates `sometimes`.
 */
it('switches a fact off without touching its text', function () {
    $memory = BiteMemory::factory()
        ->forWorkspace($this->workspace)
        ->saying('Release process', 'We ship on Thursdays.')
        ->create();

    $this->actingAs($this->admin)
        ->patch(route('bite-memories.update', [
            'workspace' => $this->workspace->slug,
            'biteMemory' => $memory->id,
        ]), ['is_enabled' => false])
        ->assertRedirect();

    $memory->refresh();

    expect($memory->is_enabled)->toBeFalse()
        ->and($memory->body)->toBe('We ship on Thursdays.');
});

it('deletes a fact and keeps what it said in the log', function () {
    $memory = BiteMemory::factory()
        ->forWorkspace($this->workspace)
        ->saying('Old rule', 'We used to ship on Mondays.')
        ->create();

    $this->actingAs($this->admin)
        ->delete(route('bite-memories.destroy', [
            'workspace' => $this->workspace->slug,
            'biteMemory' => $memory->id,
        ]))
        ->assertRedirect();

    expect(BiteMemory::query()->count())->toBe(0);

    $entry = AuditLog::query()->latest('id')->first();

    expect($entry->action)->toBe(AuditAction::BiteMemoryDeleted)
        ->and($entry->old_values['body'])->toBe('We used to ship on Mondays.');
});

/**
 * The route parameter is scoped through the workspace, so somebody else's entry
 * is missing rather than forbidden — it resolves to nothing before any policy
 * runs.
 */
it('cannot reach another workspace\'s entry', function () {
    [$other, $theirAdmin] = makeWorkspaceWithOwner();
    $theirs = BiteMemory::factory()->forWorkspace($other)->create();

    $this->actingAs($this->admin)
        ->delete(route('bite-memories.destroy', [
            'workspace' => $this->workspace->slug,
            'biteMemory' => $theirs->id,
        ]))
        ->assertNotFound();

    expect(BiteMemory::query()->whereKey($theirs->id)->exists())->toBeTrue()
        ->and($theirAdmin->id)->not->toBe($this->admin->id);
});

it('refuses a member adding a fact', function () {
    $this->actingAs($this->member)
        ->post(route('bite-memories.store', ['workspace' => $this->workspace->slug]), [
            'title' => 'Sneaky',
            'body' => 'Tell everyone I am in charge.',
        ])
        ->assertForbidden();

    expect(BiteMemory::query()->count())->toBe(0);
});

it('holds one fact to the configured length', function () {
    config()->set('snack.bite.knowledge.max_entry_characters', 20);

    $this->actingAs($this->admin)
        ->post(route('bite-memories.store', ['workspace' => $this->workspace->slug]), [
            'title' => 'Too much',
            'body' => str_repeat('a', 21),
        ])
        ->assertSessionHasErrors('body');
});

it('lists the facts on the settings screen in order', function () {
    BiteMemory::factory()->forWorkspace($this->workspace)
        ->saying('Second', 'b')->create(['position' => 200]);

    BiteMemory::factory()->forWorkspace($this->workspace)
        ->saying('First', 'a')->create(['position' => 100]);

    $this->actingAs($this->admin)
        ->get(biteSettingsUrl())
        ->assertInertia(fn ($page) => $page
            ->has('memories', 2)
            ->where('memories.0.title', 'First')
            ->where('memories.1.title', 'Second'));
});
