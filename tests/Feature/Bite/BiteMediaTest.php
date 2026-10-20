<?php

use App\Actions\Bite\StartBiteChat;
use App\Actions\Conversations\SendConversationMessage;
use App\Ai\Agents\Bite;
use App\Ai\BiteAttachments;
use App\Ai\BiteContext;
use App\Ai\BiteMedia;
use App\Ai\Tools\GenerateImage;
use App\Ai\Tools\GenerateVideo;
use App\Enums\GenerationStatus;
use App\Enums\MagnificModel;
use App\Enums\MediaKind;
use App\Jobs\GenerateBiteMedia;
use App\Models\BiteGeneration;
use App\Models\BiteSetting;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\BiteBot;
use App\Services\ImageProcessor;
use App\Services\Magnific;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;

/**
 * Bite making images and videos with Magnific.
 *
 * Paid and slow, so: the key is the switch, the tools only queue, and the job
 * that does the work must end every request with a message — the result, or
 * the reason there is none.
 */
beforeEach(function () {
    Queue::fake();
    Storage::fake('local');

    config()->set('snack.bite.enabled', true);
    config()->set('snack.uploads.disk', 'local');
    config()->set('ai.providers.anthropic.key', 'test-key');
    config()->set('services.magnific.key', 'magnific-key');
    config()->set('services.magnific.url', 'https://api.magnific.test');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->bot = app(BiteBot::class)->accountFor($this->workspace);

    $this->chat = app(StartBiteChat::class)->handle($this->workspace, $this->owner, 'make me something');
    $this->request = app(SendConversationMessage::class)->handle($this->chat, $this->owner, 'make me something');
});

function mediaContext(?Message $message = null): BiteContext
{
    return new BiteContext(
        workspace: test()->workspace,
        user: test()->owner,
        container: test()->chat,
        message: ($message ?? test()->request)->load('attachments'),
    );
}

function imageAttachmentOn(Message $message): MessageAttachment
{
    Storage::disk('local')->put('workspaces/source.png', 'source-bytes');

    return MessageAttachment::create([
        'workspace_id' => $message->workspace_id,
        'message_id' => $message->id,
        'uploaded_by' => $message->sender_id,
        'disk' => 'local',
        'path' => 'workspaces/source.png',
        'original_name' => 'source.png',
        'mime_type' => 'image/png',
        'size' => 12,
        'metadata' => [],
    ]);
}

// -- Model requests -----------------------------------------------------------

it('spells each model request the way that model expects', function (MagnificModel $model, ?string $image, array $expected) {
    expect($model->payload('a cat', '16:9', 7, $image))->toBe(['prompt' => 'a cat', ...$expected]);
})->with([
    'mystic, named ratio' => [MagnificModel::Mystic, null, ['aspect_ratio' => 'widescreen_16_9']],
    'mystic, style reference' => [MagnificModel::Mystic, 'b64', ['style_reference' => 'b64', 'aspect_ratio' => 'widescreen_16_9']],
    'flux 2 klein, input image' => [MagnificModel::Flux2Klein, 'b64', ['input_image' => 'b64', 'aspect_ratio' => 'widescreen_16_9']],
    'recraft, text only ignores the image' => [MagnificModel::Recraft4, 'b64', ['aspect_ratio' => 'widescreen_16_9']],
    'nano banana, colon ratio' => [MagnificModel::NanoBananaPro, null, ['aspect_ratio' => '16:9']],
    'kling, string duration' => [MagnificModel::Kling26Pro, 'b64', ['image' => 'b64', 'aspect_ratio' => 'widescreen_16_9', 'duration' => '5']],
    'veo, integer duration' => [MagnificModel::Veo31, null, ['aspect_ratio' => '16:9', 'duration' => 6]],
    'wan, pixel size' => [MagnificModel::Wan26, null, ['size' => '1920*1080', 'duration' => '5']],
    'hailuo, first frame and no ratio' => [MagnificModel::Hailuo23, 'b64', ['first_frame_image' => 'b64', 'duration' => 6]],
    'ltx, text only ignores the image' => [MagnificModel::Ltx2Pro, 'b64', ['duration' => 6]],
]);

it('falls back to the nearest shape a model supports', function () {
    expect(MagnificModel::Veo31->payload('x', '3:4', null, null)['aspect_ratio'])->toBe('9:16')
        ->and(MagnificModel::Kling26Pro->payload('x', '4:3', null, null)['aspect_ratio'])->toBe('widescreen_16_9');
});

it('polls the shared status endpoint for model families', function () {
    expect(MagnificModel::Kling26Pro->statusPath(true, 't1'))->toBe('/v1/ai/image-to-video/kling-v2-6/t1')
        ->and(MagnificModel::Kling3Turbo->statusPath(false, 't1'))->toBe('/v1/ai/text-to-video/kling-v3-turbo/t1')
        ->and(MagnificModel::Veo31->submitPath(true))->toBe('/v1/ai/image-to-video/veo-3-1');
});

// -- Tools --------------------------------------------------------------------

it('queues an image with the model that was asked for', function () {
    $tool = new GenerateImage(mediaContext(), app(BiteMedia::class));

    $result = (string) $tool->handle(new Request([
        'prompt' => 'A launch banner',
        'model' => 'recraft-4',
        'aspect_ratio' => '16:9',
    ]));

    $generation = BiteGeneration::sole();

    expect($result)->toContain('Recraft 4')
        ->and($generation->kind)->toBe(MediaKind::Image)
        ->and($generation->model)->toBe(MagnificModel::Recraft4)
        ->and($generation->aspect_ratio)->toBe('16:9')
        ->and($generation->user_id)->toBe($this->owner->id)
        ->and($generation->request_message_id)->toBe($this->request->id);

    Queue::assertPushed(GenerateBiteMedia::class);
});

it('refuses a video model for an image', function () {
    $tool = new GenerateImage(mediaContext(), app(BiteMedia::class));

    $result = (string) $tool->handle(new Request(['prompt' => 'x', 'model' => 'veo-3.1']));

    expect($result)->toContain('makes videos')
        ->and(BiteGeneration::count())->toBe(0);
});

it('honours the composer selection even when the agent requests a different model', function (string $selection, MediaKind $kind) {
    $this->request->update(['metadata' => ['bite_model' => $selection]]);
    $tool = $kind === MediaKind::Image
        ? new GenerateImage(mediaContext(), app(BiteMedia::class))
        : new GenerateVideo(mediaContext(), app(BiteMedia::class));

    $tool->handle(new Request([
        'prompt' => 'A rocket at sunrise',
        'model' => $kind === MediaKind::Image ? 'mystic' : 'kling-2.6-pro',
    ]));

    expect(BiteGeneration::sole()->model->value)->toBe($selection);
})->with([
    ['recraft-4', MediaKind::Image],
    ['veo-3.1', MediaKind::Video],
]);

it('uses the image attached to the message as a reference', function () {
    $reference = imageAttachmentOn($this->request);
    $tool = new GenerateImage(mediaContext(), app(BiteMedia::class));

    $result = (string) $tool->handle(new Request(['prompt' => 'the same poster, in blue']));

    $generation = BiteGeneration::sole();

    expect($result)->toContain('using [source.png] as a reference')
        ->and($generation->source_attachment_id)->toBe($reference->id)
        ->and($generation->model)->toBe(MagnificModel::Mystic);
});

it('uses the previous image in the chat as a reference when asked to', function () {
    $earlier = Message::query()->whereKey($this->request->id)->sole();
    $reference = imageAttachmentOn($earlier);
    $next = app(SendConversationMessage::class)->handle($this->chat, $this->owner, 'another one like that');

    $tool = new GenerateImage(mediaContext($next), app(BiteMedia::class));
    $tool->handle(new Request(['prompt' => 'same style, a dog', 'use_previous_image' => true]));

    expect(BiteGeneration::sole()->source_attachment_id)->toBe($reference->id);
});

it('refuses a text-only image model when there is a reference', function () {
    imageAttachmentOn($this->request);
    $tool = new GenerateImage(mediaContext(), app(BiteMedia::class));

    $result = (string) $tool->handle(new Request(['prompt' => 'x', 'model' => 'recraft-4']));

    expect($result)->toContain('cannot use a reference image')
        ->and(BiteGeneration::count())->toBe(0);
});

it('animates the image attached to the message', function () {
    $source = imageAttachmentOn($this->request);
    $tool = new GenerateVideo(mediaContext(), app(BiteMedia::class));

    $result = (string) $tool->handle(new Request(['prompt' => 'make it move', 'duration_seconds' => 9]));

    $generation = BiteGeneration::sole();

    expect($result)->toContain('from [source.png]')
        ->and($generation->source_attachment_id)->toBe($source->id)
        ->and($generation->model)->toBe(MagnificModel::Kling26Pro)
        ->and($generation->duration)->toBe(10);
});

it('animates the previous image in the chat when asked to', function () {
    $earlier = Message::query()->whereKey($this->request->id)->sole();
    $source = imageAttachmentOn($earlier);
    $next = app(SendConversationMessage::class)->handle($this->chat, $this->owner, 'now animate it');

    $tool = new GenerateVideo(mediaContext($next), app(BiteMedia::class));
    $tool->handle(new Request(['prompt' => 'gentle zoom', 'use_previous_image' => true]));

    expect(BiteGeneration::sole()->source_attachment_id)->toBe($source->id);
});

it('refuses to start a text-only video model from an image', function () {
    imageAttachmentOn($this->request);
    $tool = new GenerateVideo(mediaContext(), app(BiteMedia::class));

    $result = (string) $tool->handle(new Request(['prompt' => 'x', 'model' => 'ltx-2-pro']));

    expect($result)->toContain('cannot start from an image')
        ->and(BiteGeneration::count())->toBe(0);
});

it('only hands Bite the media tools when there is a Magnific key', function () {
    $tools = fn (): array => array_map(
        fn ($tool): string => $tool::class,
        [...(new Bite(mediaContext(), $this->bot, new BiteAttachments))->tools()],
    );

    expect($tools())->toContain(GenerateImage::class, GenerateVideo::class);

    config()->set('services.magnific.key', null);

    expect($tools())->not->toContain(GenerateImage::class)
        ->and($tools())->not->toContain(GenerateVideo::class);
});

// -- The job ------------------------------------------------------------------

function queuedGeneration(array $attributes = []): BiteGeneration
{
    return BiteGeneration::factory()->create([
        'workspace_id' => test()->workspace->id,
        'user_id' => test()->owner->id,
        'request_message_id' => test()->request->id,
        ...$attributes,
    ]);
}

function runGeneration(BiteGeneration $generation): GenerateBiteMedia
{
    $job = (new GenerateBiteMedia($generation))->withFakeQueueInteractions();

    $job->handle(app(Magnific::class), app(BiteBot::class), app(ImageProcessor::class));

    return $job;
}

it('submits the task and checks back later', function () {
    Http::fake(['api.magnific.test/v1/ai/mystic' => Http::response(['data' => ['task_id' => 'task-1', 'status' => 'CREATED']])]);

    $generation = queuedGeneration(['prompt' => 'a cat', 'aspect_ratio' => '1:1']);

    runGeneration($generation)->assertReleased(GenerateBiteMedia::IMAGE_POLL_SECONDS);

    expect($generation->fresh())
        ->task_id->toBe('task-1')
        ->status->toBe(GenerationStatus::Processing);

    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('x-magnific-api-key', 'magnific-key')
        && $request['aspect_ratio'] === 'square_1_1');
});

it('sends the reference image along with the prompt', function () {
    Http::fake(['api.magnific.test/v1/ai/mystic' => Http::response(['data' => ['task_id' => 'task-1', 'status' => 'CREATED']])]);

    $reference = imageAttachmentOn($this->request);
    $generation = queuedGeneration(['prompt' => 'a cat', 'source_attachment_id' => $reference->id]);

    runGeneration($generation);

    Http::assertSent(fn (HttpRequest $request): bool => $request['style_reference'] === base64_encode('source-bytes'));
});

it('scales a large reference image down before sending it', function () {
    Http::fake(['api.magnific.test/v1/ai/mystic' => Http::response(['data' => ['task_id' => 'task-1', 'status' => 'CREATED']])]);

    $photo = imagecreatetruecolor(2100, 700);
    ob_start();
    imagejpeg($photo);
    Storage::disk('local')->put('workspaces/photo.jpg', (string) ob_get_clean());

    $reference = MessageAttachment::create([
        'workspace_id' => $this->workspace->id,
        'message_id' => $this->request->id,
        'uploaded_by' => $this->owner->id,
        'disk' => 'local',
        'path' => 'workspaces/photo.jpg',
        'original_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1,
        'metadata' => [],
    ]);

    runGeneration(queuedGeneration(['prompt' => 'a cat', 'source_attachment_id' => $reference->id]));

    Http::assertSent(function (HttpRequest $request): bool {
        [$width, $height] = getimagesizefromstring(base64_decode($request['style_reference']));

        return $width === GenerateBiteMedia::SOURCE_MAX_EDGE && $height === 683;
    });
});

it('checks on a video less often than an image', function () {
    Http::fake(['api.magnific.test/*' => Http::response(['data' => ['task_id' => 'task-3', 'status' => 'IN_PROGRESS']])]);

    $generation = queuedGeneration(['kind' => MediaKind::Video, 'model' => MagnificModel::Veo31, 'duration' => 6, 'task_id' => 'task-3', 'status' => GenerationStatus::Processing]);

    runGeneration($generation)->assertReleased(GenerateBiteMedia::VIDEO_POLL_SECONDS);
});

it('posts the finished video into the chat as Bite', function () {
    Http::fake([
        'api.magnific.test/v1/ai/image-to-video/kling-v2-6/task-2' => Http::response(['data' => ['task_id' => 'task-2', 'status' => 'COMPLETED', 'generated' => ['https://cdn.magnific.test/out.mp4']]]),
        'cdn.magnific.test/*' => Http::response('mp4-bytes', 200, ['Content-Type' => 'video/mp4']),
    ]);

    $generation = queuedGeneration(['prompt' => 'Rocket at sunrise', 'kind' => MediaKind::Video, 'model' => MagnificModel::Kling26Pro, 'duration' => 5, 'task_id' => 'task-2', 'status' => GenerationStatus::Processing]);

    runGeneration($generation)->assertNotReleased();

    $generation->refresh();
    $reply = Message::query()->whereKey($generation->result_message_id)->sole();

    expect($generation->status)->toBe(GenerationStatus::Completed)
        ->and($reply->sender_id)->toBe($this->bot->id)
        ->and($reply->conversation_id)->toBe($this->chat->id)
        ->and($reply->body)->toContain('Kling 2.6 Pro')
        ->and($generation->attachment->message_id)->toBe($reply->id)
        ->and($generation->attachment->original_name)->toBe('rocket-at-sunrise.mp4')
        ->and($generation->attachment->mime_type)->toBe('video/mp4');

    expect(Storage::disk('local')->get($generation->attachment->path))->toBe('mp4-bytes');
});

it('tells the person when Magnific fails', function () {
    Http::fake(['api.magnific.test/*' => Http::response(['data' => ['task_id' => 'task-3', 'status' => 'FAILED']])]);

    $generation = queuedGeneration(['task_id' => 'task-3', 'status' => GenerationStatus::Processing]);

    runGeneration($generation);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and(Message::query()->where('sender_id', $this->bot->id)->latest('id')->value('body'))
        ->toContain("couldn't make that image");
});

it('gives up once a generation outlasts its budget', function () {
    Http::fake(['api.magnific.test/*' => Http::response(['data' => ['task_id' => 'task-4', 'status' => 'IN_PROGRESS']])]);

    $generation = queuedGeneration(['task_id' => 'task-4', 'status' => GenerationStatus::Processing]);

    $this->travel(6)->minutes();

    runGeneration($generation)->assertNotReleased();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error->toContain('too long');
});

it('switches media generation on for workspaces that saved settings before it existed', function () {
    $setting = BiteSetting::factory()->forWorkspace($this->workspace)->create([
        'capabilities' => ['search_messages', 'create_task'],
    ]);

    (require database_path('migrations/2026_10_15_143430_enable_media_capabilities_for_bite.php'))->up();

    expect($setting->fresh()->capabilities)
        ->toBe(['search_messages', 'create_task', 'generate_image', 'generate_video']);

    $tools = array_map(
        fn ($tool): string => $tool::class,
        [...(new Bite(mediaContext(), $this->bot, new BiteAttachments))->tools()],
    );

    expect($tools)->toContain(GenerateImage::class, GenerateVideo::class);
});

it('keeps storage errors out of the chat', function () {
    Http::fake([
        'api.magnific.test/*' => Http::response(['data' => ['task_id' => 'task-5', 'status' => 'COMPLETED', 'generated' => ['https://cdn.magnific.test/out.png']]]),
        'cdn.magnific.test/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    Storage::shouldReceive('disk')->andReturnSelf();
    Storage::shouldReceive('put')->andThrow(new RuntimeException(
        'Error executing "PutObject" on "https://secret-bucket.example.com/x.png"; InvalidAccessKeyId',
    ));

    $generation = queuedGeneration(['task_id' => 'task-5', 'status' => GenerationStatus::Processing]);

    runGeneration($generation);

    $reply = Message::query()->where('sender_id', $this->bot->id)->latest('id')->value('body');

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($reply)->toContain('could not save the file')
        ->and($reply)->not->toContain('secret-bucket')
        ->and($reply)->not->toContain('InvalidAccessKeyId');
});
