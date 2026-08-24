<?php

use App\Enums\ChannelType;
use App\Models\Canvas;
use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * A file shared into a channel by someone who is in it.
 */
function shareFile(
    Channel $channel,
    User $sender,
    string $name = 'budget.pdf',
    string $mime = 'application/pdf',
): MessageAttachment {
    $message = makeMessage($channel, $sender);

    return MessageAttachment::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'message_id' => $message->id,
        'uploaded_by' => $sender->id,
        'original_name' => $name,
        'mime_type' => $mime,
    ]);
}

it('lists files from channels the viewer can read', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $canvas = Canvas::factory()->forWorkspace($workspace, $owner)->create([
        'title' => 'Quarterly plan',
        'updated_at' => now()->subMinute(),
    ]);
    $file = shareFile($channel, $owner, 'quarterly.pdf');

    actingAs($owner)
        ->get(route('files.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('files/index')
            ->has('files', 1)
            ->where('files.0.name', 'quarterly.pdf')
            ->has('file_canvas_sidebar.recent', 2)
            ->has('file_canvas_sidebar.starred', 0)
            ->where('file_canvas_sidebar.recent.0.id', 'file:'.$file->id)
            ->where('file_canvas_sidebar.recent.0.file.name', 'quarterly.pdf')
            ->where('file_canvas_sidebar.recent.1.id', 'canvas:'.$canvas->public_id),
        );
});

it('hides files shared in a private channel the viewer is not in', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $private = makeChannel($workspace, ChannelType::Private);
    addToChannel($private, $owner);

    shareFile($private, $owner, 'secret.pdf');

    $outsider = joinWorkspace($workspace);

    actingAs($outsider)
        ->get(route('files.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->has('files', 0)
            ->has('file_canvas_sidebar.recent', 0)
            ->has('file_canvas_sidebar.starred', 0),
        );
});

it('hides files from a conversation the viewer is not part of', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $confidant = joinWorkspace($workspace);
    $outsider = joinWorkspace($workspace);

    $conversation = makeConversation($workspace, [$owner, $confidant]);
    $message = makeConversationMessage($conversation, $owner);

    MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $owner->id,
    ]);

    actingAs($confidant)
        ->get(route('files.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('files', 1));

    actingAs($outsider)
        ->get(route('files.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('files', 0));
});

it('never lists an upload that was never sent', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $owner->id,
    ]);

    actingAs($owner)
        ->get(route('files.index', $workspace))
        ->assertInertia(fn ($page) => $page->has('files', 0));
});

it('never lists another workspace\'s files', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    shareFile($channel, $owner, 'ours.pdf');

    $elsewhere = makeWorkspace();
    $theirChannel = makeChannel($elsewhere);
    $theirOwner = joinWorkspace($elsewhere, $elsewhere->owner);
    addToChannel($theirChannel, $theirOwner);
    shareFile($theirChannel, $theirOwner, 'theirs.pdf');

    actingAs($owner)
        ->get(route('files.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->has('files', 1)
            ->where('files.0.name', 'ours.pdf'),
        );
});

it('filters by kind', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    shareFile($channel, $owner, 'photo.png', 'image/png');
    shareFile($channel, $owner, 'notes.pdf', 'application/pdf');

    actingAs($owner)
        ->get(route('files.index', [$workspace, 'kind' => 'image']))
        ->assertInertia(fn ($page) => $page
            ->has('files', 1)
            ->where('files.0.name', 'photo.png'),
        );
});

it('filters by file name', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    shareFile($channel, $owner, 'Q3-invoice.pdf');
    shareFile($channel, $owner, 'roadmap.pdf');

    actingAs($owner)
        ->get(route('files.index', [$workspace, 'q' => 'invoice']))
        ->assertInertia(fn ($page) => $page
            ->has('files', 1)
            ->where('files.0.name', 'Q3-invoice.pdf'),
        );
});

it('will not widen the result set through the channel filter', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $private = makeChannel($workspace, ChannelType::Private);
    addToChannel($private, $owner);
    shareFile($private, $owner, 'secret.pdf');

    $outsider = joinWorkspace($workspace);

    // Naming the private channel explicitly narrows to nothing rather than
    // reaching into it: the visibility constraint is applied first and always.
    actingAs($outsider)
        ->get(route('files.index', [$workspace, 'channel' => $private->id]))
        ->assertInertia(fn ($page) => $page->has('files', 0));
});

it('offers only channels the viewer can see as filter options', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $public = makeChannel($workspace, ChannelType::Public, ['name' => 'Design']);
    $private = makeChannel($workspace, ChannelType::Private, ['name' => 'Board']);
    addToChannel($private, $owner);

    $member = joinWorkspace($workspace);
    addToChannel($public, $member);

    actingAs($member)
        ->get(route('files.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->where('channels', fn ($channels) => collect($channels)
                ->pluck('id')
                ->doesntContain($private->id)),
        );
});

it('pages with a cursor rather than an offset', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    config()->set('snack.uploads.browser_page_size', 2);

    foreach (range(1, 3) as $index) {
        shareFile($channel, $owner, "file-{$index}.pdf");
    }

    $response = actingAs($owner)->get(route('files.index', $workspace));

    $cursor = $response->viewData('page')['props']['next_cursor'];

    expect($cursor)->not->toBeNull();

    actingAs($owner)
        ->get(route('files.index', [$workspace, 'before' => $cursor]))
        ->assertInertia(fn ($page) => $page
            ->has('files', 1)
            ->where('files.0.name', 'file-1.pdf'),
        );
});

it('is not reachable from outside the workspace', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $stranger = joinWorkspace(makeWorkspace());

    actingAs($stranger)
        ->get(route('files.index', $workspace))
        ->assertNotFound();
});
