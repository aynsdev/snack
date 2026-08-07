<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\IncomingWebhook;
use App\Models\Message;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
});

it('lets an admin see the webhooks screen', function () {
    $this->actingAs($this->owner)
        ->get("/w/{$this->workspace->slug}/webhooks")
        ->assertOk();
});

it('refuses an ordinary member', function () {
    // Not a presentational gate: the screen shows live tokens, so reading it is
    // the same right as issuing one. See IncomingWebhookPolicy.
    $this->actingAs($this->member)
        ->get("/w/{$this->workspace->slug}/webhooks")
        ->assertForbidden();
});

it('creates a hook with a working URL', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/webhooks", [
            'name' => 'Deploy notifications',
            'channel_id' => $this->channel->id,
        ])
        ->assertRedirect();

    $webhook = IncomingWebhook::query()->firstOrFail();

    expect($webhook->channel_id)->toBe($this->channel->id)
        ->and($webhook->workspace_id)->toBe($this->workspace->id)
        // The stored hash is what lookups touch; the plaintext round-trips
        // through the encrypted column so the screen can show the URL again.
        ->and($webhook->token_hash)->toBe(IncomingWebhook::hashToken($webhook->token));

    $this->postJson("/hooks/{$webhook->token}", ['text' => 'it works'])->assertCreated();
});

it('does not create a bot until the hook first fires', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/webhooks", [
            'name' => 'Never wired up',
            'channel_id' => $this->channel->id,
        ]);

    // An admin who copies a URL and forgets about it should not have put an
    // account in the channel's member list claiming otherwise.
    expect(IncomingWebhook::query()->firstOrFail()->bot_user_id)->toBeNull()
        ->and($this->channel->fresh()->members()->count())->toBe(0);
});

it('refuses a channel in another workspace', function () {
    [$other] = makeWorkspaceWithOwner();
    $elsewhere = makeChannel($other);

    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/webhooks", [
            'name' => 'Cross tenant',
            'channel_id' => $elsewhere->id,
        ])
        ->assertSessionHasErrors('channel_id');

    expect(IncomingWebhook::query()->count())->toBe(0);
});

it('refuses an archived channel', function () {
    $this->channel->update(['is_archived' => true]);

    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/webhooks", [
            'name' => 'Into the void',
            'channel_id' => $this->channel->id,
        ])
        ->assertSessionHasErrors('channel_id');
});

it('records the creation in the audit log without the token', function () {
    $this->actingAs($this->owner)
        ->post("/w/{$this->workspace->slug}/webhooks", [
            'name' => 'Deploy notifications',
            'channel_id' => $this->channel->id,
        ]);

    $entry = AuditLog::query()->where('action', AuditAction::WebhookCreated)->firstOrFail();

    expect($entry->new_values['channel'])->toBe($this->channel->slug)
        // The log is read and exported. Copying a live credential into it would
        // make it a second place the URL leaks from.
        ->and(json_encode($entry->new_values))
        ->not->toContain(IncomingWebhook::query()->firstOrFail()->token);
});

it('renames the hook and its bot together', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create(['name' => 'CI']);

    $this->postJson("/hooks/{$webhook->token}", ['text' => 'first'])->assertCreated();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}", ['name' => 'Builds'])
        ->assertRedirect();

    expect($webhook->fresh()->name)->toBe('Builds')
        ->and(Message::query()->firstOrFail()->sender->fresh()->name)->toBe('Builds');
});

it('cannot be re-aimed at another channel', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();
    $elsewhere = makeChannel($this->workspace);

    $this->actingAs($this->owner)->patch(
        "/w/{$this->workspace->slug}/webhooks/{$webhook->id}",
        ['name' => 'Moved', 'channel_id' => $elsewhere->id],
    )->assertRedirect();

    // A live URL is already in somebody's CI config. Silently redirecting it
    // would let an admin fill a private channel with build noise by editing a
    // dropdown, with nothing on the calling side showing anything had changed.
    expect($webhook->fresh()->channel_id)->toBe($this->channel->id);
});

it('stops deliveries the moment a hook is revoked', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();
    $token = $webhook->token;

    $this->postJson("/hooks/{$token}", ['text' => 'before'])->assertCreated();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/revoke")
        ->assertRedirect();

    $this->postJson("/hooks/{$token}", ['text' => 'after'])->assertNotFound();

    expect(Message::query()->count())->toBe(1)
        // The row survives, so "what was that, and who set it up" still has an
        // answer the next morning.
        ->and($webhook->fresh()->isRevoked())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::WebhookRevoked)->exists())->toBeTrue();
});

it('turns a hook back on with the URL it had before', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();
    $token = $webhook->token;

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/revoke");

    $this->postJson("/hooks/{$token}", ['text' => 'while off'])->assertNotFound();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/restore")
        ->assertRedirect();

    /*
     * The *same* token, which is the whole point: an admin who clicked the
     * wrong row needs the deploy script on the other side to keep working, not
     * a fresh URL to go and paste into it.
     */
    $this->postJson("/hooks/{$token}", ['text' => 'back on'])->assertCreated();

    expect($webhook->fresh()->isRevoked())->toBeFalse()
        ->and($webhook->fresh()->token)->toBe($token)
        ->and(Message::query()->count())->toBe(1);
});

it('shows the URL again once a hook is restored', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->revoked()->create([
        'name' => 'Back from the dead',
    ]);

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/restore");

    $page = $this->actingAs($this->owner)
        ->get("/w/{$this->workspace->slug}/webhooks")
        ->viewData('page');

    expect($page['props']['webhooks'][0]['url'])->toContain($webhook->token);
});

it('records the restore separately from an edit', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->revoked()->create();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/restore");

    // Its own action, not a `webhook.updated`: a URL that went dead and came
    // back is what somebody investigating an incident is looking for, and
    // folded into "updated" it would read as a rename.
    expect(AuditLog::query()->where('action', AuditAction::WebhookRestored)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::WebhookUpdated)->exists())->toBeFalse();
});

it('writes nothing when restoring a hook that was never off', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/restore")
        ->assertRedirect();

    // A stale tab, or two admins clicking at once. A "turned back on" entry for
    // a hook that never went off is a lie in an append-only log.
    expect($webhook->fresh()->isRevoked())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::WebhookRestored)->exists())->toBeFalse();
});

it('refuses a member trying to turn one back on', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->revoked()->create();

    // The same right as creating one: restoring puts a live URL back into the
    // world, so it must cost what issuing one costs.
    $this->actingAs($this->member)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/restore")
        ->assertForbidden();

    expect($webhook->fresh()->isRevoked())->toBeTrue();
});

it('turns signing on and hands over a secret', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();

    expect($webhook->requiresSignature())->toBeFalse();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/signing", ['mode' => 'enable'])
        ->assertRedirect();

    $webhook = $webhook->fresh();

    expect($webhook->requiresSignature())->toBeTrue()
        // Long enough that the secret is never the weak half of the HMAC.
        ->and($webhook->signing_secret)->toHaveLength(64);

    // And it is refused without one from that moment on.
    $this->postJson("/hooks/{$webhook->token}", ['text' => 'unsigned'])->assertStatus(401);
});

it('does not rotate the secret when signing is enabled twice', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->signed()->create();
    $secret = $webhook->signing_secret;

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/signing", ['mode' => 'enable']);

    // Somebody re-saving a form must not break a live integration.
    expect($webhook->fresh()->signing_secret)->toBe($secret)
        ->and(AuditLog::query()->where('action', AuditAction::WebhookSigningEnabled)->count())->toBe(0);
});

it('rotates the secret without touching the URL', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->signed()->create();
    [$token, $old] = [$webhook->token, $webhook->signing_secret];

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/signing", ['mode' => 'rotate'])
        ->assertRedirect();

    $webhook = $webhook->fresh();

    /*
     * The remedy for a leaked secret, and why it is not a whole new hook: the
     * URL is untouched, so every system holding it keeps its configuration and
     * only the secret line changes.
     */
    expect($webhook->signing_secret)->not->toBe($old)
        ->and($webhook->token)->toBe($token)
        ->and($webhook->requiresSignature())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::WebhookSecretRotated)->exists())->toBeTrue();
});

it('turns signing off and accepts a bare URL again', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->signed()->create();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/signing", ['mode' => 'disable'])
        ->assertRedirect();

    expect($webhook->fresh()->requiresSignature())->toBeFalse();

    $this->postJson("/hooks/{$webhook->token}", ['text' => 'unsigned again'])->assertCreated();
});

it('never writes the secret into the audit log', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/signing", ['mode' => 'enable']);

    // The log is read, exported, and often shipped somewhere central. A
    // credential in it would defeat the encryption on the column it came from.
    $entries = AuditLog::query()->get()->map(fn ($entry): string => json_encode([
        $entry->old_values, $entry->new_values,
    ]))->implode(' ');

    expect($entries)->not->toContain($webhook->fresh()->signing_secret);
});

it('refuses a member trying to change signing', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->signed()->create();

    // Its own policy method: disabling *removes* a security control, so the
    // permission must not ride along with the one that governs renaming.
    $this->actingAs($this->member)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/signing", ['mode' => 'disable'])
        ->assertForbidden();

    expect($webhook->fresh()->requiresSignature())->toBeTrue();
});

it('rejects a signing mode it does not recognise', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();

    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/signing", ['mode' => 'whatever'])
        ->assertSessionHasErrors('mode');
});

it('shows the secret to an admin and withholds a revoked hook\'s', function () {
    $live = IncomingWebhook::factory()->forChannel($this->channel)->signed()->create(['name' => 'Live']);
    $dead = IncomingWebhook::factory()->forChannel($this->channel)->signed()->revoked()->create(['name' => 'Dead']);

    $page = $this->actingAs($this->owner)
        ->get("/w/{$this->workspace->slug}/webhooks")
        ->viewData('page');

    $secrets = collect($page['props']['webhooks'])->pluck('signing_secret', 'name');

    expect($secrets['Live'])->toBe($live->signing_secret)
        ->and($secrets['Dead'])->toBeNull();
});

it('does not move the revocation time when revoked twice', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();

    $this->actingAs($this->owner)->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/revoke");

    $first = $webhook->fresh()->revoked_at;

    $this->travel(5)->minutes();

    $this->actingAs($this->owner)->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/revoke");

    // Two admins clicking at once, or a stale tab. Re-stamping would move the
    // moment the hook actually stopped working.
    expect($webhook->fresh()->revoked_at->timestamp)->toBe($first->timestamp);
});

it('stops a live hook on the way out when it is deleted', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();
    $token = $webhook->token;

    $this->actingAs($this->owner)
        ->delete("/w/{$this->workspace->slug}/webhooks/{$webhook->id}")
        ->assertRedirect();

    $this->postJson("/hooks/{$token}", ['text' => 'after'])->assertNotFound();

    expect(IncomingWebhook::query()->count())->toBe(0)
        // Both events, so the log distinguishes housekeeping from an incident.
        ->and(AuditLog::query()->where('action', AuditAction::WebhookRevoked)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::WebhookDeleted)->exists())->toBeTrue();
});

it('keeps the messages a deleted hook posted', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create(['name' => 'CI']);

    $this->postJson("/hooks/{$webhook->token}", ['text' => 'shipped'])->assertCreated();

    $this->actingAs($this->owner)->delete("/w/{$this->workspace->slug}/webhooks/{$webhook->id}");

    // A channel's history must not lose its authors because somebody tidied an
    // integrations list.
    expect(Message::query()->firstOrFail()->sender->name)->toBe('CI');
});

it('hides another workspace\'s hook behind a 404', function () {
    [$other, $otherOwner] = makeWorkspaceWithOwner();
    $elsewhere = IncomingWebhook::factory()->forChannel(makeChannel($other))->create();

    // Scoped at the router through Workspace::webhooks(), so an id from another
    // tenant never reaches a policy that would have to catch it.
    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$elsewhere->id}", ['name' => 'Stolen'])
        ->assertNotFound();

    expect($elsewhere->fresh()->name)->not->toBe('Stolen');
});

it('refuses a member trying to create, edit, or revoke one', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->channel)->create();

    $this->actingAs($this->member)
        ->post("/w/{$this->workspace->slug}/webhooks", [
            'name' => 'Mine now',
            'channel_id' => $this->channel->id,
        ])
        ->assertForbidden();

    $this->actingAs($this->member)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}", ['name' => 'Mine now'])
        ->assertForbidden();

    $this->actingAs($this->member)
        ->patch("/w/{$this->workspace->slug}/webhooks/{$webhook->id}/revoke")
        ->assertForbidden();

    expect($webhook->fresh()->isRevoked())->toBeFalse();
});

it('shows the URL for a live hook and withholds it for a revoked one', function () {
    $live = IncomingWebhook::factory()->forChannel($this->channel)->create(['name' => 'Live']);
    $dead = IncomingWebhook::factory()->forChannel($this->channel)->revoked()->create(['name' => 'Dead']);

    $page = $this->actingAs($this->owner)
        ->get("/w/{$this->workspace->slug}/webhooks")
        ->viewData('page');

    $urls = collect($page['props']['webhooks'])->pluck('url', 'name');

    expect($urls['Live'])->toContain($live->token)
        // Rendering a dead credential invites somebody to paste it into a config
        // and wonder why nothing arrives.
        ->and($urls['Dead'])->toBeNull();
});
