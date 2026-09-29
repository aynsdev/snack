<?php

use App\Enums\ChannelType;
use App\Enums\MentionType;
use App\Enums\WorkspaceRole;
use App\Models\Announcement;
use App\Models\Mention;
use App\Models\Message;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->channel = makeChannel($this->workspace, ChannelType::Announcement, ['name' => 'announcements']);
    addToChannel($this->channel, $this->owner);
    addToChannel($this->channel, $this->member);

    $this->base = "/w/{$this->workspace->slug}/hr/announcements";
});

function announcementPayload(array $overrides = []): array
{
    return [
        'title' => 'Office move',
        'body' => 'We move to the new office on Monday.',
        'is_pinned' => false,
        ...$overrides,
    ];
}

it('posts the announcement to the chosen channel as the admin, notifying everyone', function () {
    $this->actingAs($this->owner)
        ->post($this->base, announcementPayload([
            'channel_id' => $this->channel->id,
            'notify_everyone' => true,
        ]))
        ->assertSessionHasNoErrors();

    $announcement = Announcement::query()->firstOrFail();
    $message = Message::query()->findOrFail($announcement->message_id);

    expect($message->channel_id)->toBe($this->channel->id)
        ->and($message->sender_id)->toBe($this->owner->id)
        ->and($message->body)->toContain('**Office move**')
        ->and($message->body)->toContain('@everyone')
        ->and($message->body)->toContain('/hr/announcements')
        ->and($announcement->notified_everyone)->toBeTrue();

    // The broadcast mention reaches the member through the normal pipeline.
    expect(Mention::query()
        ->where('message_id', $message->id)
        ->where('mentioned_user_id', $this->member->id)
        ->where('mention_type', MentionType::Everyone)
        ->exists())->toBeTrue();
});

it('posts quietly when nobody is to be notified', function () {
    $this->actingAs($this->owner)
        ->post($this->base, announcementPayload([
            'channel_id' => $this->channel->id,
            'notify_everyone' => false,
        ]))
        ->assertSessionHasNoErrors();

    $message = Message::query()->firstOrFail();

    expect($message->body)->not->toContain('@everyone')
        ->and(Mention::query()->count())->toBe(0);
});

it('publishes in HR only when no channel is chosen', function () {
    $this->actingAs($this->owner)
        ->post($this->base, announcementPayload(['notify_everyone' => true]))
        ->assertSessionHasNoErrors();

    expect(Announcement::query()->firstOrFail())
        ->channel_id->toBeNull()
        ->message_id->toBeNull()
        ->notified_everyone->toBeFalse();

    expect(Message::query()->count())->toBe(0);
});

it('refuses a channel the admin is not in', function () {
    $private = makeChannel($this->workspace, ChannelType::Private);

    $this->actingAs($this->owner)
        ->post($this->base, announcementPayload(['channel_id' => $private->id]))
        ->assertSessionHasErrors('channel_id');

    expect(Announcement::query()->count())->toBe(0);
});

it('refuses an archived channel', function () {
    $this->channel->update(['is_archived' => true]);

    $this->actingAs($this->owner)
        ->post($this->base, announcementPayload(['channel_id' => $this->channel->id]))
        ->assertSessionHasErrors('channel_id');
});

it('refuses a channel from another workspace', function () {
    [$other] = makeWorkspaceWithOwner();
    $foreign = makeChannel($other);

    $this->actingAs($this->owner)
        ->post($this->base, announcementPayload(['channel_id' => $foreign->id]))
        ->assertSessionHasErrors('channel_id');
});

it('updates the channel post in place when the announcement is edited', function () {
    $this->actingAs($this->owner)
        ->post($this->base, announcementPayload(['channel_id' => $this->channel->id, 'notify_everyone' => true]));

    $announcement = Announcement::query()->firstOrFail();

    $this->actingAs($this->owner)
        ->patch("{$this->base}/{$announcement->id}", announcementPayload(['title' => 'Office move, updated']))
        ->assertSessionHasNoErrors();

    $message = Message::query()->findOrFail($announcement->message_id);

    expect(Message::query()->count())->toBe(1)
        ->and($message->body)->toContain('**Office move, updated**')
        ->and($message->edited_at)->not->toBeNull();
});

it('takes the channel post down with the announcement', function () {
    $this->actingAs($this->owner)
        ->post($this->base, announcementPayload(['channel_id' => $this->channel->id]));

    $announcement = Announcement::query()->firstOrFail();

    $this->actingAs($this->owner)
        ->delete("{$this->base}/{$announcement->id}")
        ->assertRedirect();

    expect(Announcement::query()->count())->toBe(0)
        ->and(Message::query()->whereKey($announcement->message_id)->exists())->toBeFalse();
});

it('offers announcement channels first when picking where to post', function () {
    $general = makeChannel($this->workspace, ChannelType::Public, ['name' => 'aaa-general', 'is_default' => true]);
    addToChannel($general, $this->owner);

    $this->actingAs($this->owner)
        ->get($this->base)
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.name', 'announcements')
            ->where('channels.1.name', 'aaa-general'));

    $this->actingAs($this->member)
        ->get($this->base)
        ->assertInertia(fn (Assert $page) => $page->where('channels', []));
});
