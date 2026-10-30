<?php

use App\Actions\Projects\SaveTask;
use App\Actions\Projects\SetProjectArchived;
use App\Enums\OutboundEventStatus;
use App\Models\IncomingWebhook;
use App\Services\Outbound\OutboundDelivery;
use App\Services\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Delivery against a real HTTP server rather than a fake.
 *
 * The fake proves what Snack *meant* to send; this proves what actually goes
 * over a socket — the raw bytes a receiver signs-checks, the headers as a server
 * sees them, and a real status code coming back. PHP's built-in server stands in
 * for the dispatcher, writing each request it receives to disk.
 */
beforeEach(function () {
    $this->inbox = sys_get_temp_dir().'/snack-dispatcher-'.Str::random(12);
    File::ensureDirectoryExists($this->inbox);

    File::put($this->inbox.'/router.php', <<<'PHP'
        <?php
        file_put_contents(
            __DIR__.'/request-'.hrtime(true).'.json',
            json_encode([
                'method' => $_SERVER['REQUEST_METHOD'],
                'uri' => $_SERVER['REQUEST_URI'],
                'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
                'body' => file_get_contents('php://input'),
            ]),
        );
        http_response_code((int) (@file_get_contents(__DIR__.'/status') ?: 200));
        echo '{"ok":true}';
        PHP);

    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) Str::afterLast(stream_socket_get_name($probe, false), ':');
    fclose($probe);

    $this->server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", $this->inbox.'/router.php'], $this->inbox);
    $this->server->start();

    $deadline = microtime(true) + 5;

    while (! ($socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) && microtime(true) < $deadline) {
        usleep(50_000);
    }

    if ($socket === false) {
        $this->fail('The stand-in dispatcher did not start: '.$this->server->getErrorOutput());
    }

    fclose($socket);

    enableAgentDispatcher("http://127.0.0.1:{$port}/v1/snack/events");
    config(['snack.agents.allow_insecure' => true]);

    [$this->workspace] = makeWorkspaceWithOwner();
});

afterEach(function () {
    $this->server->stop(0);
    File::deleteDirectory($this->inbox);
});

/**
 * Every request the stand-in dispatcher has received, oldest first.
 *
 * @return array<int, array{method: string, uri: string, headers: array<string, string>, body: string}>
 */
function receivedByDispatcher(string $inbox): array
{
    $files = glob($inbox.'/request-*.json') ?: [];
    sort($files, SORT_NATURAL);

    return array_map(fn (string $file): array => json_decode((string) file_get_contents($file), true), $files);
}

/**
 * @param  array{headers: array<string, string>, body: string}  $received
 */
function verdictOnReceived(array $received): ?string
{
    $request = Request::create('/v1/snack/events', 'POST', server: [
        'HTTP_X_SNACK_TIMESTAMP' => $received['headers']['x-snack-timestamp'] ?? '',
        'HTTP_X_SNACK_SIGNATURE' => $received['headers']['x-snack-signature'] ?? '',
    ], content: $received['body']);

    return app(WebhookSignature::class)->verify(
        (new IncomingWebhook)->forceFill(['signing_secret' => dispatcherSecret()]),
        $request,
    );
}

it('delivers bytes a real receiver can verify', function () {
    $event = recordOutboundEvent($this->workspace, [
        'project' => ['id' => 1, 'name' => 'Pédi — “quotes” & slashes/'],
        'before' => null,
    ]);

    $received = receivedByDispatcher($this->inbox);

    expect($received)->toHaveCount(1);

    [$request] = $received;
    $body = json_decode($request['body'], true);

    expect($request['method'])->toBe('POST')
        ->and($request['uri'])->toBe('/v1/snack/events')
        ->and($request['headers']['content-type'])->toBe('application/json')
        ->and($request['headers']['user-agent'])->toBe('Snack-Outbound/1')
        ->and($request['headers']['x-snack-event'])->toBe('project.agent_settings_changed')
        ->and(verdictOnReceived($request))->toBeNull()
        ->and($body['event_id'])->toBe($event->event_id)
        ->and($body['sequence'])->toBe($event->sequence)
        ->and($body['data']['project']['name'])->toBe('Pédi — “quotes” & slashes/')
        ->and($event->fresh()->status)->toBe(OutboundEventStatus::Delivered)
        ->and($event->fresh()->last_status)->toBe(200);
});

it('retries a real refusal and sends the same body again', function () {
    File::put($this->inbox.'/status', '500');

    $event = recordOutboundEvent($this->workspace, ['probe' => true]);

    expect($event->fresh()->status)->toBe(OutboundEventStatus::Pending)
        ->and($event->fresh()->last_status)->toBe(500);

    File::put($this->inbox.'/status', '204');
    $this->travel(11)->seconds();

    expect(app(OutboundDelivery::class)->attempt($event->id))->toBeTrue();

    [$first, $second] = receivedByDispatcher($this->inbox);

    expect($second['body'])->toBe($first['body'])
        ->and(verdictOnReceived($second))->toBeNull()
        ->and($event->fresh()->status)->toBe(OutboundEventStatus::Delivered)
        ->and($event->fresh()->last_status)->toBe(204);
});

it('leaves an event pending when nothing is listening', function () {
    $this->server->stop(0);

    $event = recordOutboundEvent($this->workspace, ['probe' => true]);

    expect($event->fresh()->status)->toBe(OutboundEventStatus::Pending)
        ->and($event->fresh()->last_status)->toBeNull()
        ->and($event->fresh()->last_error)->not->toBeEmpty()
        ->and($event->fresh()->attempts)->toBe(1);
});

it('delivers the v1.1 events in sequence order to a real receiver', function () {
    $owner = $this->workspace->owner;
    $channel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent']);
    $project = makeAgentProject($this->workspace, $owner, $channel, ['name' => 'Pedi Client App', 'key' => 'PCA']);
    $task = makeTask($project, $owner, ['title' => 'Before']);

    app(SaveTask::class)->update($task, $owner, ['title' => 'After']);
    app(SetProjectArchived::class)->handle($project, $owner, archived: true);

    $received = array_map(fn (array $request): array => json_decode($request['body'], true), receivedByDispatcher($this->inbox));

    expect(array_column($received, 'type'))->toBe(['task.content_changed', 'project.agent_settings_changed'])
        ->and(array_column($received, 'sequence'))->toBe([1, 2])
        ->and($received[0]['data']['changed'])->toBe(['title'])
        ->and($received[0]['data']['task']['title'])->toBe('After')
        ->and($received[1]['data']['project']['archived'])->toBeTrue()
        ->and(array_map(verdictOnReceived(...), receivedByDispatcher($this->inbox)))->toBe([null, null]);
});
