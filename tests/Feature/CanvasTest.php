<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Http\Resources\AttachmentResource;
use App\Models\Canvas;
use App\Models\MessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

it('lists canvases in the current workspace newest first', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $older = Canvas::factory()->forWorkspace($workspace, $owner)->create([
        'title' => 'Team handbook',
        'updated_at' => now()->subDay(),
    ]);
    $newer = Canvas::factory()->forWorkspace($workspace, $owner)->create([
        'title' => 'Launch plan',
        'updated_at' => now(),
    ]);
    Canvas::factory()->create(['title' => 'Another workspace']);
    $channel = makeChannel($workspace);
    Canvas::factory()->forWorkspace($workspace, $owner)->create([
        'title' => 'Channel notes',
        'channel_id' => $channel->id,
    ]);

    actingAs($owner)
        ->get(route('canvases.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('canvases/index')
            ->has('canvases', 2)
            ->where('canvases.0.id', $newer->public_id)
            ->where('canvases.0.title', 'Launch plan')
            ->where('canvases.1.id', $older->public_id)
            ->missing('canvases.0.body')
            ->where('abilities.create', true),
        );
});

it('searches canvas titles and bodies case-insensitively', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    Canvas::factory()->forWorkspace($workspace, $owner)->create([
        'title' => 'Quarterly plan',
        'body' => 'Launch the new billing portal.',
    ]);
    Canvas::factory()->forWorkspace($workspace, $owner)->create([
        'title' => 'Office guide',
        'body' => 'Where everything lives.',
    ]);

    actingAs($owner)
        ->get(route('canvases.index', $workspace).'?q=BILLING')
        ->assertInertia(fn ($page) => $page
            ->has('canvases', 1)
            ->where('canvases.0.title', 'Quarterly plan')
            ->where('filters.q', 'BILLING'),
        );
});

it('creates a canvas and opens it', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);

    actingAs($member)
        ->post(route('canvases.store', $workspace))
        ->assertRedirect();

    $canvas = Canvas::query()->sole();

    expect($canvas->title)->toBe('Untitled')
        ->and($canvas->body)->toBe('')
        ->and($canvas->content)->toBe([
            'type' => 'doc',
            'content' => [['type' => 'paragraph']],
        ])
        ->and($canvas->created_by)->toBe($member->id)
        ->and($canvas->updated_by)->toBe($member->id)
        ->and($canvas->public_id)->toHaveLength(26);

    actingAs($member)
        ->get(route('canvases.show', [$workspace, $canvas]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('canvases/show')
            ->where('canvas.id', $canvas->public_id)
            ->where('canvas.body', '')
            ->where('canvas.content.type', 'doc')
            ->where('canvas.can_update', true)
            ->where('canvas.can_delete', true),
        );
});

it('validates structured canvas content and rejects unsafe links', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create();

    actingAs($owner)
        ->patch(route('canvases.update', [$workspace, $canvas]), [
            'title' => 'Unsafe',
            'content' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Click me',
                        'marks' => [[
                            'type' => 'link',
                            'attrs' => ['href' => 'javascript:alert(1)'],
                        ]],
                    ]],
                ]],
            ],
        ])
        ->assertSessionHasErrors(['content']);

    expect($canvas->refresh()->title)->not->toBe('Unsafe');
});

it('allows workspace members to collaborate on a canvas', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create();

    actingAs($member)
        ->patch(route('canvases.update', [$workspace, $canvas]), [
            'title' => 'Updated brief',
            'content' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'A clearer plan.',
                        'marks' => [['type' => 'bold']],
                    ]],
                ]],
            ],
        ])
        ->assertRedirect();

    expect($canvas->refresh())
        ->title->toBe('Updated brief')
        ->body->toBe('A clearer plan.')
        ->updated_by->toBe($member->id);
});

it('creates a channel canvas immediately for a channel member', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    actingAs($owner)
        ->post(route('channels.canvases.store', [$workspace, $channel]))
        ->assertRedirect();

    $canvas = Canvas::query()->sole();

    expect($canvas->channel_id)->toBe($channel->id)
        ->and($canvas->conversation_id)->toBeNull()
        ->and($canvas->title)->toBe('Untitled');

    actingAs($owner)
        ->get(route('channels.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('canCreateCanvas', true)
            ->has('canvases', 1)
            ->where('canvases.0.id', $canvas->public_id),
        );
});

it('does not reveal a private channel canvas to non-members', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($workspace);
    $channel = makeChannel($workspace, ChannelType::Private);
    addToChannel($channel, $owner);
    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create([
        'channel_id' => $channel->id,
    ]);

    actingAs($outsider)
        ->post(route('channels.canvases.store', [$workspace, $channel]))
        ->assertNotFound();

    actingAs($outsider)
        ->get(route('canvases.show', [$workspace, $canvas]))
        ->assertNotFound();
});

it('creates a conversation canvas immediately for an active participant', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $participant = joinWorkspace($workspace);
    $outsider = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$owner, $participant]);

    actingAs($participant)
        ->post(route('conversations.canvases.store', [$workspace, $conversation]))
        ->assertRedirect();

    $canvas = Canvas::query()->sole();

    expect($canvas->conversation_id)->toBe($conversation->id)
        ->and($canvas->channel_id)->toBeNull();

    actingAs($participant)
        ->get(route('conversations.show', [$workspace, $conversation]))
        ->assertInertia(fn ($page) => $page
            ->where('canCreateCanvas', true)
            ->has('canvases', 1)
            ->where('canvases.0.id', $canvas->public_id),
        );

    actingAs($outsider)
        ->get(route('canvases.show', [$workspace, $canvas]))
        ->assertNotFound();
});

it('adds, replaces, and removes a private canvas cover', function () {
    Storage::fake(config('snack.uploads.disk', 'local'));
    Queue::fake();

    [$workspace, $owner] = makeWorkspaceWithOwner();
    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create();

    actingAs($owner)
        ->post(route('canvases.cover.store', [$workspace, $canvas]), [
            'cover' => UploadedFile::fake()->image('first-cover.jpg', 1600, 600),
        ])
        ->assertRedirect();

    $first = MessageAttachment::query()->sole();

    expect($first->canvas_id)->toBe($canvas->id)
        ->and($first->message_id)->toBeNull()
        ->and($first->task_id)->toBeNull()
        ->and($first->asset_id)->toBeNull()
        ->and(MessageAttachment::query()->orphaned()->count())->toBe(0);

    Storage::disk($first->disk)->assertExists($first->path);

    $coverUrl = AttachmentResource::payload($first)['preview_url'];

    expect($coverUrl)->toBeString();
    actingAs($owner)->get((string) $coverUrl)->assertOk();

    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    actingAs($guest)->get((string) $coverUrl)->assertNotFound();

    actingAs($owner)
        ->get(route('canvases.show', [$workspace, $canvas]))
        ->assertInertia(fn ($page) => $page
            ->where('canvas.cover_url', fn (string $url) => str_contains($url, 'inline=1')),
        );

    actingAs($owner)
        ->post(route('canvases.cover.store', [$workspace, $canvas]), [
            'cover' => UploadedFile::fake()->image('replacement.png', 1400, 700),
        ])
        ->assertRedirect();

    Storage::disk($first->disk)->assertMissing($first->path);
    expect(MessageAttachment::query()->count())->toBe(1);

    $replacement = MessageAttachment::query()->sole();

    actingAs($owner)
        ->delete(route('canvases.cover.destroy', [$workspace, $canvas]))
        ->assertRedirect();

    expect(MessageAttachment::query()->count())->toBe(0);
    Storage::disk($replacement->disk)->assertMissing($replacement->path);
});

it('validates canvas covers and hides them from guests', function () {
    Storage::fake(config('snack.uploads.disk', 'local'));

    [$workspace, $owner] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);
    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create();

    actingAs($owner)
        ->post(route('canvases.cover.store', [$workspace, $canvas]), [
            'cover' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ])
        ->assertSessionHasErrors(['cover']);

    actingAs($guest)
        ->post(route('canvases.cover.store', [$workspace, $canvas]), [
            'cover' => UploadedFile::fake()->image('cover.jpg'),
        ])
        ->assertNotFound();

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('only lets the creator or an admin delete a canvas', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $creator = joinWorkspace($workspace);
    $otherMember = joinWorkspace($workspace);
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    $mine = Canvas::factory()->forWorkspace($workspace, $creator)->create();
    $administered = Canvas::factory()->forWorkspace($workspace, $owner)->create();

    actingAs($otherMember)
        ->delete(route('canvases.destroy', [$workspace, $mine]))
        ->assertForbidden();

    actingAs($creator)
        ->delete(route('canvases.destroy', [$workspace, $mine]))
        ->assertRedirect(route('canvases.index', $workspace));

    actingAs($admin)
        ->delete(route('canvases.destroy', [$workspace, $administered]))
        ->assertRedirect(route('canvases.index', $workspace));

    expect(Canvas::query()->count())->toBe(0);
});

it('hides canvases from guests', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    actingAs($guest)
        ->get(route('canvases.index', $workspace))
        ->assertNotFound();
});

it('404s a canvas belonging to another workspace', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    [$elsewhere, $otherOwner] = makeWorkspaceWithOwner();
    $canvas = Canvas::factory()->forWorkspace($elsewhere, $otherOwner)->create();

    actingAs($owner)
        ->get(route('canvases.show', [$workspace, $canvas]))
        ->assertNotFound();
});
