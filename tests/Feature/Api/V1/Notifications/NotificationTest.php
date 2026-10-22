<?php

use App\Actions\Messages\SyncMentions;
use App\Enums\NotificationLevel;
use App\Enums\NotificationReason;
use App\Enums\WorkspaceRole;
use App\Jobs\DeliverMessageNotifications;
use App\Models\Notification;

/**
 * The notification centre and the member's own preferences over the API.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->channel = makeChannel($this->workspace);
    addToChannel($this->channel, $this->owner);
    addToChannel($this->channel, $this->member);

    $this->base = "/api/v1/w/{$this->workspace->slug}";

    $this->notify = function (): void {
        $message = makeMessage($this->channel, $this->owner, "@{$this->member->username} hi");

        app(SyncMentions::class)->handle($message, $this->owner);
        DeliverMessageNotifications::dispatch($message->id);
    };
});

it('lists the member\'s notifications', function () {
    ($this->notify)();

    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/notifications")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', NotificationReason::Mention->value);
});

it('marks a single notification read', function () {
    ($this->notify)();

    $notification = Notification::query()->forWorkspace($this->workspace, $this->member)->sole();

    $this->withToken(mobileToken($this->member))
        ->postJson("{$this->base}/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect($notification->fresh()->isRead())->toBeTrue();
});

it('marks everything read', function () {
    ($this->notify)();
    ($this->notify)();

    $this->withToken(mobileToken($this->member))
        ->patchJson("{$this->base}/notifications/read")
        ->assertNoContent();

    expect(Notification::query()->forWorkspace($this->workspace, $this->member)->whereNull('read_at')->count())->toBe(0);
});

it('keeps a non-member out of the centre', function () {
    [, $stranger] = makeWorkspaceWithOwner();

    $this->withToken(mobileToken($stranger))
        ->getJson("{$this->base}/notifications")
        ->assertNotFound();
});

it('shows the notification preferences with the overridden channels', function () {
    $this->channel->members()->where('user_id', $this->member->id)->update(['is_muted' => true]);

    $this->withToken(mobileToken($this->member))
        ->getJson("{$this->base}/notification-preferences")
        ->assertOk()
        ->assertJsonStructure(['data' => ['preferences' => ['default_level'], 'levels', 'channels', 'conversations']])
        ->assertJsonCount(1, 'data.channels')
        ->assertJsonPath('data.channels.0.slug', $this->channel->slug);
});

it('saves the notification preferences', function () {
    $this->withToken(mobileToken($this->member))
        ->patchJson("{$this->base}/notification-preferences", [
            'default_level' => NotificationLevel::Mentions->value,
            'email_enabled' => false,
            'digest_enabled' => true,
            'do_not_disturb' => ['start' => '22:00', 'end' => '07:00'],
        ])
        ->assertOk()
        ->assertJsonPath('data.preferences.default_level', NotificationLevel::Mentions->value);
});

it('rejects an unknown notification level', function () {
    $this->withToken(mobileToken($this->member))
        ->patchJson("{$this->base}/notification-preferences", [
            'default_level' => 'loud',
            'email_enabled' => true,
            'digest_enabled' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('default_level');
});
