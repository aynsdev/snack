<?php

use App\Actions\Projects\AnnounceAssignment;
use App\Enums\PresenceStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\DeliverMessageNotifications;
use App\Models\PushDevice;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Push notifications to the mobile app through FCM.
 *
 * FCM and Google's token endpoint are faked; the service account is a key
 * generated per run, so the JWT signing path is exercised for real.
 */
beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);

    config([
        'services.fcm.project_id' => 'snack-test',
        'services.fcm.credentials' => json_encode([
            'client_email' => 'push@snack-test.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]),
    ]);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-access-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => fn () => $this->fcmResponse ?? Http::response(['name' => 'projects/snack-test/messages/1']),
        'exp.host/*' => fn () => $this->expoResponse ?? Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-1']]),
    ]);

    [$this->workspace, $this->sender] = makeWorkspaceWithOwner();
    $this->recipient = joinWorkspace($this->workspace, role: WorkspaceRole::Member);
    $this->recipient->forceFill(['presence_status' => PresenceStatus::Offline, 'last_seen_at' => now()->subHour()])->save();
    $this->device = PushDevice::factory()->for($this->recipient)->create(['platform' => 'ios']);
    $this->conversation = makeConversation($this->workspace, [$this->sender, $this->recipient]);

    $this->sendDirect = fn (string $body) => DeliverMessageNotifications::dispatch(
        makeConversationMessage($this->conversation, $this->sender, $body)->id,
    );

    $this->fcmSends = fn () => Http::recorded(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com'));
    $this->expoSends = fn () => Http::recorded(fn (Request $request) => str_contains($request->url(), 'exp.host'));
});

it('pushes a direct message to an away recipient\'s phone', function () {
    ($this->sendDirect)('Are you coming to standup?');

    $sends = ($this->fcmSends)();

    expect($sends)->toHaveCount(1);

    [$request] = $sends->first();

    expect($request->url())->toBe('https://fcm.googleapis.com/v1/projects/snack-test/messages:send')
        ->and($request->header('Authorization')[0])->toBe('Bearer google-access-token')
        ->and($request['message']['token'])->toBe($this->device->token)
        ->and($request['message']['notification']['title'])->toBe($this->sender->name)
        ->and($request['message']['notification']['body'])->toContain('Are you coming to standup?')
        ->and($request['message']['data']['reason'])->toBe('direct_message')
        ->and($request['message']['data']['workspace'])->toBe($this->workspace->slug)
        ->and($request['message']['data']['container'])->toBe($this->conversation->public_id)
        ->and($request['message']['apns']['payload']['aps']['sound'])->toBe('snack.wav');
});

it('does not push to someone who is using Snack right now', function () {
    $this->recipient->forceFill(['presence_status' => PresenceStatus::Online, 'last_seen_at' => now()])->save();

    ($this->sendDirect)('hello');

    expect(($this->fcmSends)())->toBeEmpty();
});

it('respects do not disturb', function () {
    $this->recipient->forceFill(['presence_status' => PresenceStatus::DoNotDisturb])->save();

    ($this->sendDirect)('hello');

    expect(($this->fcmSends)())->toBeEmpty();
});

it('respects the member turning push off', function () {
    $this->recipient->membershipIn($this->workspace)
        ->forceFill(['notification_preferences' => ['push' => ['enabled' => false]]])
        ->save();

    ($this->sendDirect)('hello');

    expect(($this->fcmSends)())->toBeEmpty();
});

it('sends nothing when FCM is not configured', function () {
    config(['services.fcm.credentials' => null]);

    ($this->sendDirect)('hello');

    expect(($this->fcmSends)())->toBeEmpty();
});

it('still buzzes for a message folded into an unread row', function () {
    ($this->sendDirect)('first');
    ($this->sendDirect)('second');

    expect(($this->fcmSends)())->toHaveCount(2)
        ->and($this->recipient->notifications()->count())->toBe(1);
});

it('forgets a device FCM says is gone', function () {
    $this->fcmResponse = Http::response([
        'error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]],
    ], 404);

    ($this->sendDirect)('hello');

    expect(PushDevice::find($this->device->id))->toBeNull();
});

it('keeps a device through a transient FCM failure', function () {
    $this->fcmResponse = Http::response(['error' => ['code' => 503, 'message' => 'Unavailable']], 503);

    ($this->sendDirect)('hello');

    expect(PushDevice::find($this->device->id))->not->toBeNull()
        ->and($this->recipient->notifications()->count())->toBe(1);
});

it('pushes a task assignment', function () {
    $project = makeProject($this->workspace, $this->sender);
    addToProject($project, $this->recipient);
    $task = makeTask($project, $this->sender, ['assignee_id' => $this->recipient->id]);

    app(AnnounceAssignment::class)->handle($task, $this->sender, null);

    [$request] = ($this->fcmSends)()->first();

    expect($request['message']['data']['reason'])->toBe('task_assigned')
        ->and($request['message']['data']['task'])->toBe($task->reference())
        ->and($request['message']['notification']['body'])->toBe($task->title);
});

describe('through Expo', function () {
    beforeEach(function () {
        $this->device->delete();
        $this->expoDevice = PushDevice::factory()->expo()->for($this->recipient)->create();
    });

    it('sends an Expo token through Expo\'s push service, not FCM', function () {
        ($this->sendDirect)('Lunch?');

        expect(($this->fcmSends)())->toBeEmpty();

        [$request] = ($this->expoSends)()->first();

        expect($request->url())->toBe('https://exp.host/--/api/v2/push/send')
            ->and($request['to'])->toBe($this->expoDevice->token)
            ->and($request['title'])->toBe($this->sender->name)
            ->and($request['body'])->toContain('Lunch?')
            ->and($request['data']['reason'])->toBe('direct_message')
            ->and($request['sound'])->toBe('snack.wav')
            ->and($request['channelId'])->toBe('messages');
    });

    it('needs no FCM credentials', function () {
        config(['services.fcm.credentials' => null]);

        ($this->sendDirect)('hello');

        expect(($this->expoSends)())->toHaveCount(1);
    });

    it('sends the access token when enhanced push security is on', function () {
        config(['services.expo.access_token' => 'expo-secret']);

        ($this->sendDirect)('hello');

        [$request] = ($this->expoSends)()->first();

        expect($request->header('Authorization')[0])->toBe('Bearer expo-secret');
    });

    it('forgets a device Expo says is no longer registered', function () {
        $this->expoResponse = Http::response(['data' => [
            'status' => 'error',
            'message' => 'The recipient device is not registered with FCM.',
            'details' => ['error' => 'DeviceNotRegistered'],
        ]]);

        ($this->sendDirect)('hello');

        expect(PushDevice::find($this->expoDevice->id))->toBeNull();
    });
});
