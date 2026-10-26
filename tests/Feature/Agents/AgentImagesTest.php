<?php

use App\Enums\ChannelType;
use App\Enums\ConnectorKind;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Screenshots a LocalAgent run attaches to its thread: agent connectors only,
 * PNG or JPEG only, into a thread its owner may post in, through the same
 * storage and rendering path as a file dragged into the composer.
 */
beforeEach(function () {
    Queue::fake();
    Storage::fake('local');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);
    $this->channel = makeChannel($this->workspace, ChannelType::Private, ['name' => 'pca-agent', 'slug' => 'pca-agent']);
    addToChannel($this->channel, $this->owner);
    addToChannel($this->channel, $this->dev);
    $this->root = makeMessage($this->channel, $this->dev, 'PCA-7 thread');

    [, $this->token] = connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
    auth()->forgetGuards();
});

function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

/**
 * @param  list<array<string, string>>  $attachments
 */
function postImages(object $test, array $attachments, ?string $token = null, ?int $replyTo = null, string $channel = 'pca-agent'): TestResponse
{
    $response = $test->withToken($token ?? $test->token)
        ->postJson('/mcp', mcpCall('tools/call', ['name' => 'send_message', 'arguments' => [
            'channel' => $channel,
            'body' => '📱 Tested on Android emulator (Pixel_9)',
            'reply_to' => $replyTo ?? $test->root->id,
            'attachments' => $attachments,
        ]]))
        ->assertOk();
    auth()->forgetGuards();

    return $response;
}

function pngImage(string $name = 'screenshot-001.png'): array
{
    return ['filename' => $name, 'mime_type' => 'image/png', 'data' => base64_encode(tinyPng())];
}

it('attaches screenshots to the agent\'s reply in an allowed thread', function () {
    $response = postImages($this, [pngImage(), pngImage('screenshot-002.png')]);

    expect($response->json('result.isError'))->toBeFalse();

    $reply = Message::query()->latest('id')->firstOrFail();
    $files = MessageAttachment::query()->where('message_id', $reply->id)->get();

    expect($reply->parent_message_id)->toBe($this->root->id)
        ->and($reply->has_attachments)->toBeTrue()
        ->and($reply->metadata['agent'] ?? null)->not->toBeNull()
        ->and($files)->toHaveCount(2)
        ->and($files->pluck('mime_type')->unique()->all())->toBe(['image/png'])
        ->and($files->pluck('original_name')->all())->toBe(['screenshot-001.png', 'screenshot-002.png']);

    foreach ($files as $file) {
        Storage::disk('local')->assertExists($file->path);
    }
});

it('renders the screenshots through the existing message resource', function () {
    postImages($this, [pngImage()]);

    $replies = $this->actingAs(User::query()->findOrFail($this->owner->id))
        ->getJson(route('threads.show', [$this->workspace->slug, $this->root->id]))
        ->assertOk()
        ->json('replies');

    expect($replies)->toHaveCount(1)
        ->and($replies[0]['attachments'])->toHaveCount(1)
        ->and($replies[0]['attachments'][0])->toMatchArray(['name' => 'screenshot-001.png', 'mime_type' => 'image/png', 'kind' => 'image']);
});

it('refuses attachments from an ordinary connector', function () {
    [, $ordinary] = connectAs($this->dev, $this->workspace, ConnectorKind::Token);
    auth()->forgetGuards();

    $response = postImages($this, [pngImage()], $ordinary);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('only accepted from an agent connector')
        ->and(MessageAttachment::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(1);
});

it('refuses a wrong type, a disguised file, bad base64 and an oversized image', function (array $image, string $error) {
    $response = postImages($this, [$image]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain($error)
        ->and(MessageAttachment::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(1);
})->with([
    'svg' => [['filename' => 'x.svg', 'mime_type' => 'image/svg+xml', 'data' => base64_encode('<svg/>')], 'mime_type is invalid'],
    'html named png' => [['filename' => 'x.png', 'mime_type' => 'image/png', 'data' => base64_encode('<html><script>alert(1)</script></html>')], 'is not a image/png image'],
    'png declared jpeg' => [['filename' => 'x.jpg', 'mime_type' => 'image/jpeg', 'data' => base64_encode(tinyPng())], 'is not a image/jpeg image'],
    'bad base64' => [['filename' => 'x.png', 'mime_type' => 'image/png', 'data' => '!!!not-base64!!!'], 'not valid base64'],
    'oversized' => [['filename' => 'x.png', 'mime_type' => 'image/png', 'data' => base64_encode(tinyPng().str_repeat("\0", 5 * 1024 * 1024))], 'at most 5 MB'],
]);

it('refuses more than six images, and leaves nothing behind when one is bad', function () {
    expect(postImages($this, array_fill(0, 7, pngImage()))->json('result.isError'))->toBeTrue();

    $mixed = postImages($this, [pngImage(), ['filename' => 'x.png', 'mime_type' => 'image/png', 'data' => base64_encode('GIF89a')]]);

    expect($mixed->json('result.isError'))->toBeTrue()
        ->and(MessageAttachment::query()->count())->toBe(0);
});

it('only posts images into a thread the owner can post in', function () {
    $secret = makeChannel($this->workspace, ChannelType::Private, ['name' => 'secret', 'slug' => 'secret']);
    addToChannel($secret, $this->owner);
    $hidden = makeMessage($secret, $this->owner, 'not for the dev');

    expect(postImages($this, [pngImage()], channel: 'secret', replyTo: $hidden->id)->json('result.isError'))->toBeTrue()
        ->and(postImages($this, [pngImage()], replyTo: $hidden->id)->json('result.isError'))->toBeTrue();

    $topLevel = $this->withToken($this->token)
        ->postJson('/mcp', mcpCall('tools/call', ['name' => 'send_message', 'arguments' => [
            'channel' => 'pca-agent', 'body' => 'no thread', 'attachments' => [pngImage()],
        ]]))
        ->assertOk();

    expect($topLevel->json('result.content.0.text'))->toContain('only be posted as a reply in a thread')
        ->and(MessageAttachment::query()->count())->toBe(0);
});

it('rate-limits images per person', function () {
    RateLimiter::increment('agent-images:'.$this->dev->id, 60, 28);

    $response = postImages($this, [pngImage(), pngImage(), pngImage()]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('Too many images')
        ->and(MessageAttachment::query()->count())->toBe(0);
});
