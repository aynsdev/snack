<?php

use App\Enums\PresenceStatus;
use App\Models\ConversationParticipant;
use App\Models\Huddle;
use App\Models\PushDevice;
use App\Notifications\HuddleStartedNotification;
use App\Services\NotificationDelivery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Starting a huddle in a DM or group DM rings the other participants' phones.
 *
 * Two layers, tested separately: *who* is rung, asserted against a faked
 * notification with everybody away and holding a phone, so the only thing
 * deciding the audience is the audience rule; and *what* their phone receives,
 * asserted end to end against a faked Expo so the push `data` contract the
 * mobile app routes on is pinned exactly.
 */
function startConversationHuddle($test, $workspace, $conversation, $user)
{
    return $test->actingAs($user)->postJson(route('conversations.huddle.store', [
        'workspace' => $workspace->slug,
        'conversation' => $conversation->public_id,
    ]));
}

/**
 * Away from Snack with a registered phone — everything
 * {@see NotificationDelivery::shouldPush()} asks of a recipient,
 * so a faked notification's `via()` resolves to `push` and the send is recorded.
 */
function awayWithPhone($user)
{
    $user->forceFill(['presence_status' => PresenceStatus::Offline, 'last_seen_at' => now()->subHour()])->save();
    PushDevice::factory()->expo()->for($user)->create();

    return $user;
}

beforeEach(function () {
    [$this->workspace, $this->starter] = makeWorkspaceWithOwner();
    $this->other = joinWorkspace($this->workspace);
    $this->conversation = makeConversation($this->workspace, [$this->starter, $this->other]);
});

describe('who is rung', function () {
    beforeEach(function () {
        Notification::fake();

        awayWithPhone($this->starter);
        awayWithPhone($this->other);
    });

    it('rings the other participant of a direct conversation, not the starter', function () {
        startConversationHuddle($this, $this->workspace, $this->conversation, $this->starter)->assertOk();

        Notification::assertSentTo($this->other, HuddleStartedNotification::class);
        Notification::assertNotSentTo($this->starter, HuddleStartedNotification::class);
    });

    it('rings every other participant of a group conversation', function () {
        $third = awayWithPhone(joinWorkspace($this->workspace));
        $group = makeConversation($this->workspace, [$this->starter, $this->other, $third], 'Launch');

        startConversationHuddle($this, $this->workspace, $group, $this->starter)->assertOk();

        Notification::assertSentTo([$this->other, $third], HuddleStartedNotification::class);
        Notification::assertNotSentTo($this->starter, HuddleStartedNotification::class);
    });

    it('never rings for a huddle in a channel', function () {
        $channel = makeChannel($this->workspace);
        addToChannel($channel, $this->starter);
        addToChannel($channel, $this->other);

        $this->actingAs($this->starter)
            ->postJson(route('channels.huddle.store', [
                'workspace' => $this->workspace->slug,
                'channel' => $channel->slug,
            ]))
            ->assertOk();

        Notification::assertNothingSent();
    });

    /**
     * The start is the event, not the roster: the second person walking in is
     * answering the ring, and must not set it off again for anybody.
     */
    it('rings once per huddle, not once per join', function () {
        $third = awayWithPhone(joinWorkspace($this->workspace));
        $group = makeConversation($this->workspace, [$this->starter, $this->other, $third], 'Launch');

        startConversationHuddle($this, $this->workspace, $group, $this->starter)->assertOk();
        startConversationHuddle($this, $this->workspace, $group, $this->other)->assertOk();
        startConversationHuddle($this, $this->workspace, $group, $this->starter)->assertOk();

        Notification::assertSentToTimes($third, HuddleStartedNotification::class, 1);
        Notification::assertSentToTimes($this->other, HuddleStartedNotification::class, 1);
        Notification::assertNotSentTo($this->starter, HuddleStartedNotification::class);
    });

    it('does not ring a participant who muted the conversation', function () {
        ConversationParticipant::query()
            ->where('conversation_id', $this->conversation->id)
            ->where('user_id', $this->other->id)
            ->update(['is_muted' => true]);

        startConversationHuddle($this, $this->workspace, $this->conversation, $this->starter)->assertOk();

        Notification::assertNothingSent();
    });

    it('does not ring a participant who left the conversation', function () {
        $third = awayWithPhone(joinWorkspace($this->workspace));
        $group = makeConversation($this->workspace, [$this->starter, $this->other, $third], 'Launch');

        ConversationParticipant::query()
            ->where('conversation_id', $group->id)
            ->where('user_id', $third->id)
            ->update(['left_at' => now()]);

        startConversationHuddle($this, $this->workspace, $group, $this->starter)->assertOk();

        Notification::assertSentTo($this->other, HuddleStartedNotification::class);
        Notification::assertNotSentTo($third, HuddleStartedNotification::class);
    });
});

describe('what the phone receives', function () {
    beforeEach(function () {
        Http::fake([
            'exp.host/*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-1']]),
        ]);

        $this->other->forceFill(['presence_status' => PresenceStatus::Offline, 'last_seen_at' => now()->subHour()])->save();
        $this->device = PushDevice::factory()->expo()->for($this->other)->create();

        $this->expoSends = fn () => Http::recorded(fn (Request $request) => str_contains($request->url(), 'exp.host'));
    });

    it('pushes the huddle with the data the app routes on', function () {
        startConversationHuddle($this, $this->workspace, $this->conversation, $this->starter)->assertOk();

        $sends = ($this->expoSends)();

        expect($sends)->toHaveCount(1);

        [$request] = $sends->first();

        $huddleId = Huddle::query()->live()->forContainer($this->conversation)->firstOrFail()->public_id;

        expect($request['to'])->toBe($this->device->token)
            ->and($request['title'])->toBe($this->starter->name)
            ->and($request['body'])->toBe('Started a huddle — tap to join')
            ->and($request['sound'])->toBe('snack.wav')
            ->and($request['channelId'])->toBe('messages')
            ->and($request['data'])->toBe([
                'reason' => 'huddle_started',
                'workspace' => $this->workspace->slug,
                'container_kind' => 'conversation',
                'container' => $this->conversation->public_id,
                'huddle_id' => $huddleId,
            ]);
    });

    it('does not push to somebody who has Snack open — the realtime event rings them', function () {
        $this->other->forceFill(['presence_status' => PresenceStatus::Online, 'last_seen_at' => now()])->save();

        startConversationHuddle($this, $this->workspace, $this->conversation, $this->starter)->assertOk();

        expect(($this->expoSends)())->toBeEmpty();
    });

    it('respects do not disturb', function () {
        $this->other->forceFill(['presence_status' => PresenceStatus::DoNotDisturb])->save();

        startConversationHuddle($this, $this->workspace, $this->conversation, $this->starter)->assertOk();

        expect(($this->expoSends)())->toBeEmpty();
    });

    it('respects the member turning push off', function () {
        $this->other->membershipIn($this->workspace)
            ->forceFill(['notification_preferences' => ['push' => ['enabled' => false]]])
            ->save();

        startConversationHuddle($this, $this->workspace, $this->conversation, $this->starter)->assertOk();

        expect(($this->expoSends)())->toBeEmpty();
    });
});
