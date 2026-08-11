<?php

use App\Enums\ChannelType;
use App\Models\Canvas;
use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

function fileToStar(Channel $channel, User $sender, string $name = 'launch.pdf'): MessageAttachment
{
    $message = makeMessage($channel, $sender);

    return MessageAttachment::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'message_id' => $message->id,
        'uploaded_by' => $sender->id,
        'original_name' => $name,
    ]);
}

it('stars accessible files and canvases in the sidebar', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $file = fileToStar($channel, $owner);
    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create([
        'title' => 'Launch notes',
    ]);

    actingAs($owner)
        ->post(route('files.star.store', [$workspace, $file]))
        ->assertRedirect();
    actingAs($owner)
        ->post(route('canvases.star.store', [$workspace, $canvas]))
        ->assertRedirect();

    assertDatabaseHas('file_canvas_stars', [
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'message_attachment_id' => $file->id,
    ]);
    assertDatabaseHas('file_canvas_stars', [
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'canvas_id' => $canvas->id,
    ]);

    actingAs($owner)
        ->get(route('files.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->has('file_canvas_sidebar.starred', 2)
            ->where('file_canvas_sidebar.recent.0.is_starred', true)
            ->where('file_canvas_sidebar.recent.1.is_starred', true)
            ->where('file_canvas_sidebar.starred.0.is_starred', true)
            ->where('file_canvas_sidebar.starred.1.is_starred', true),
        );
});

it('keeps star and unstar actions idempotent', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create();

    actingAs($owner)->post(route('canvases.star.store', [$workspace, $canvas]));
    actingAs($owner)->post(route('canvases.star.store', [$workspace, $canvas]));

    assertDatabaseCount('file_canvas_stars', 1);

    actingAs($owner)->delete(route('canvases.star.destroy', [$workspace, $canvas]));
    actingAs($owner)->delete(route('canvases.star.destroy', [$workspace, $canvas]));

    assertDatabaseCount('file_canvas_stars', 0);
});

it('keeps stars private to the person who made them', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create();

    actingAs($owner)->post(route('canvases.star.store', [$workspace, $canvas]));

    actingAs($member)
        ->get(route('canvases.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->has('file_canvas_sidebar.starred', 0)
            ->where('file_canvas_sidebar.recent.0.is_starred', false),
        );
});

it('refuses files and canvases the viewer cannot read', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $private = makeChannel($workspace, ChannelType::Private);
    addToChannel($private, $owner);
    $file = fileToStar($private, $owner, 'secret.pdf');
    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create([
        'channel_id' => $private->id,
    ]);
    $outsider = joinWorkspace($workspace);

    actingAs($outsider)
        ->post(route('files.star.store', [$workspace, $file]))
        ->assertNotFound();
    actingAs($outsider)
        ->post(route('canvases.star.store', [$workspace, $canvas]))
        ->assertNotFound();

    assertDatabaseMissing('file_canvas_stars', ['user_id' => $outsider->id]);
});

it('rejects a target from another workspace at route binding', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    [$elsewhere, $otherOwner] = makeWorkspaceWithOwner();
    $canvas = Canvas::factory()->forWorkspace($elsewhere, $otherOwner)->create();

    actingAs($owner)
        ->post(route('canvases.star.store', [$workspace, $canvas]))
        ->assertNotFound();
});
