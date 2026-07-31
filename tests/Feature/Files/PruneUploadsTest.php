<?php

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * An attachment with bytes really on the fake disk, so the sweep can be
 * observed removing both halves.
 */
function storedAttachment(array $attributes): MessageAttachment
{
    $attachment = MessageAttachment::factory()->create([
        'disk' => 'local',
        ...$attributes,
    ]);

    Storage::disk('local')->put($attachment->path, 'bytes');

    return $attachment;
}

it('deletes an upload that was never sent, and its file', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $orphan = storedAttachment([
        'workspace_id' => $workspace->id,
        'message_id' => null,
        'uploaded_by' => $owner->id,
        'created_at' => now()->subDays(3),
    ]);

    artisan('snack:prune-uploads')->assertSuccessful();

    expect(MessageAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($orphan->path);
});

it('leaves a recent upload alone, because a composer can stay open', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    storedAttachment([
        'workspace_id' => $workspace->id,
        'message_id' => null,
        'uploaded_by' => $owner->id,
        'created_at' => now()->subMinutes(30),
    ]);

    artisan('snack:prune-uploads')->assertSuccessful();

    expect(MessageAttachment::query()->count())->toBe(1);
});

it('never touches a file that is on a sent message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner);

    $attached = storedAttachment([
        'workspace_id' => $workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $owner->id,
        'created_at' => now()->subYear(),
    ]);

    artisan('snack:prune-uploads')->assertSuccessful();

    expect(MessageAttachment::query()->whereKey($attached->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists($attached->path);
});

it('honours an overridden age threshold', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    storedAttachment([
        'workspace_id' => $workspace->id,
        'message_id' => null,
        'uploaded_by' => $owner->id,
        'created_at' => now()->subHours(3),
    ]);

    artisan('snack:prune-uploads', ['--hours' => 1])->assertSuccessful();

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('removes the row even when the file is already gone', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    $orphan = MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'created_at' => now()->subDays(3),
    ]);

    // Nothing was ever written for this path. The sweep must still collect the
    // row, or it will retry the same doomed pair forever.
    artisan('snack:prune-uploads')->assertSuccessful();

    expect(MessageAttachment::query()->whereKey($orphan->id)->exists())->toBeFalse();
});

it('backfills search vectors for messages written before the phase', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $message = makeMessage($channel, $owner, 'quarterly planning');

    // Factories write straight to the table, so nothing has indexed this yet.
    expect(vectorFor($message))->toBeNull();

    artisan('snack:reindex-messages')->assertSuccessful();

    expect(vectorFor($message))->not->toBeNull();
});

it('rebuilds every vector when asked to', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $message = makeMessage($channel, $owner, 'quarterly planning');

    artisan('snack:reindex-messages')->assertSuccessful();
    artisan('snack:reindex-messages', ['--all' => true])->assertSuccessful();

    expect(vectorFor($message))->not->toBeNull();
});

function vectorFor(Message $message): ?string
{
    return DB::table('messages')
        ->where('id', $message->id)
        ->value('search_vector');
}
