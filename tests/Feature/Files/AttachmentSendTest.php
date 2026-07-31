<?php

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * An upload parked in the given workspace by the given person.
 */
function parkUpload(
    Workspace $workspace,
    User $uploader,
    string $name = 'budget.pdf',
): MessageAttachment {
    return MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $uploader->id,
        'original_name' => $name,
    ]);
}

it('claims the named uploads for the message being sent', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $attachment = parkUpload($workspace, $owner);

    $response = actingAs($owner)->postJson(
        route('channels.messages.store', [$workspace, $channel]),
        ['body' => 'Here is the budget', 'attachment_ids' => [$attachment->id]],
    );

    $response->assertCreated();

    $message = Message::query()->sole();

    expect($attachment->refresh()->message_id)->toBe($message->id)
        ->and($message->has_attachments)->toBeTrue()
        ->and($response->json('message.attachments'))->toHaveCount(1)
        ->and($response->json('message.attachments.0.name'))->toBe('budget.pdf');
});

it('accepts a message that is files and nothing else', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $attachment = parkUpload($workspace, $owner, 'deck.pdf');

    actingAs($owner)
        ->postJson(route('channels.messages.store', [$workspace, $channel]), [
            'attachment_ids' => [$attachment->id],
        ])
        ->assertCreated();

    expect(Message::query()->sole()->body)->toBe('')
        ->and($attachment->refresh()->message_id)->not->toBeNull();
});

it('still refuses a message that is empty in both senses', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    actingAs($owner)
        ->postJson(route('channels.messages.store', [$workspace, $channel]), [
            'attachment_ids' => [],
        ])
        ->assertJsonValidationErrors('body');
});

it('will not let a member attach somebody else\'s upload', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $other);

    $theirs = parkUpload($workspace, $owner);

    actingAs($other)
        ->postJson(route('channels.messages.store', [$workspace, $channel]), [
            'body' => 'Mine now',
            'attachment_ids' => [$theirs->id],
        ])
        ->assertNotFound();

    expect($theirs->refresh()->message_id)->toBeNull()
        ->and(Message::query()->count())->toBe(0);
});

it('will not let an upload cross a workspace boundary', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $elsewhere = makeWorkspace();
    $foreign = parkUpload($elsewhere, $owner);

    actingAs($owner)
        ->postJson(route('channels.messages.store', [$workspace, $channel]), [
            'body' => 'Smuggled',
            'attachment_ids' => [$foreign->id],
        ])
        ->assertNotFound();

    expect($foreign->refresh()->message_id)->toBeNull();
});

it('will not re-point a file that is already on a message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $first = makeMessage($channel, $owner);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => $first->id,
        'uploaded_by' => $owner->id,
    ]);

    actingAs($owner)
        ->postJson(route('channels.messages.store', [$workspace, $channel]), [
            'body' => 'Again',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertNotFound();

    expect($attachment->refresh()->message_id)->toBe($first->id);
});

it('rejects the whole send when one named id does not resolve', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $mine = parkUpload($workspace, $owner);

    actingAs($owner)
        ->postJson(route('channels.messages.store', [$workspace, $channel]), [
            'body' => 'Two files',
            'attachment_ids' => [$mine->id, $mine->id + 9_999],
        ])
        ->assertNotFound();

    // Nothing partial: the good id is not claimed either.
    expect($mine->refresh()->message_id)->toBeNull()
        ->and(Message::query()->count())->toBe(0);
});

it('bounds how many files one message may carry', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    config()->set('snack.uploads.per_message', 2);

    $ids = collect(range(1, 3))
        ->map(fn (int $index): int => parkUpload($workspace, $owner, "file-{$index}.pdf")->id)
        ->all();

    actingAs($owner)
        ->postJson(route('channels.messages.store', [$workspace, $channel]), [
            'body' => 'Too many',
            'attachment_ids' => $ids,
        ])
        ->assertJsonValidationErrors('attachment_ids');
});

it('carries attachments into a direct message the same way', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);
    $conversation = makeConversation($workspace, [$owner, $other]);

    $attachment = parkUpload($workspace, $owner, 'receipt.pdf');

    actingAs($owner)
        ->postJson(route('conversations.messages.store', [$workspace, $conversation]), [
            'body' => 'Expense',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertCreated();

    expect($attachment->refresh()->message_id)->not->toBeNull();
});
