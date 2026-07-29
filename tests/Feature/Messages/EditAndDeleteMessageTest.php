<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Events\MessageDeleted;
use App\Events\MessageUpdated;
use App\Models\AuditLog;
use App\Models\Message;
use Illuminate\Support\Facades\Event;

/**
 * Editing and deleting.
 *
 * The line this file defends: authorship governs edits, moderation governs
 * deletes. An admin may remove anything, but may never rewrite what someone
 * else said — that would let an account with power put words in another
 * person's mouth, under their name, with no trace in the UI.
 */
function editUrl($workspace, Message $message): string
{
    return route('messages.update', ['workspace' => $workspace->slug, 'message' => $message->id]);
}

function deleteUrl($workspace, Message $message): string
{
    return route('messages.destroy', ['workspace' => $workspace->slug, 'message' => $message->id]);
}

it('lets an author edit their own message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner, 'teh typo');

    $this->actingAs($owner)
        ->patchJson(editUrl($workspace, $message), ['body' => 'the typo'])
        ->assertOk()
        ->assertJsonPath('message.body', 'the typo')
        ->assertJsonPath('message.is_edited', true);

    expect($message->fresh()->body)->toBe('the typo');
});

it('does not mark a message edited when the text is unchanged', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner, 'unchanged');

    $this->actingAs($owner)
        ->patchJson(editUrl($workspace, $message), ['body' => 'unchanged'])
        ->assertOk()
        ->assertJsonPath('message.is_edited', false);

    expect($message->fresh()->edited_at)->toBeNull();
});

it('stops a workspace admin from editing someone else message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $admin);

    $message = makeMessage($channel, $owner, 'the original words');

    $this->actingAs($admin)
        ->patchJson(editUrl($workspace, $message), ['body' => 'something they never said'])
        ->assertForbidden();

    expect($message->fresh()->body)->toBe('the original words');
});

it('stops a member of another workspace from reaching a message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner, 'private business');

    [$otherWorkspace] = makeWorkspaceWithOwner();
    $outsider = joinWorkspace($otherWorkspace);

    // The route binding scopes the message to the workspace in the URL, so a
    // valid id from another tenant is not even a message here.
    $this->actingAs($outsider)
        ->patchJson(editUrl($otherWorkspace, $message), ['body' => 'rewritten'])
        ->assertNotFound();

    expect($message->fresh()->body)->toBe('private business');
});

it('rejects an empty edit', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner, 'still here');

    $this->actingAs($owner)
        ->patchJson(editUrl($workspace, $message), ['body' => '   '])
        ->assertJsonValidationErrorFor('body');
});

it('honours a configured edit window', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    config()->set('snack.messages.edit_window_minutes', 15);

    $message = makeMessage($channel, $owner, 'ancient history');
    $message->forceFill(['created_at' => now()->subHour()])->save();

    $this->actingAs($owner)
        ->patchJson(editUrl($workspace, $message), ['body' => 'revisionism'])
        ->assertForbidden();
});

it('broadcasts an edit to the channel', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner, 'before');

    $this->actingAs($owner)->patchJson(editUrl($workspace, $message), ['body' => 'after']);

    Event::assertDispatched(MessageUpdated::class, function (MessageUpdated $event) use ($channel): bool {
        return $event->broadcastWith()['message']['body'] === 'after'
            && $event->broadcastOn()[0]->name === 'presence-channel.'.$channel->id;
    });
});

it('lets an author delete their own message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner, 'never mind');

    $this->actingAs($owner)->deleteJson(deleteUrl($workspace, $message))->assertOk();

    expect($message->fresh()->trashed())->toBeTrue();
});

it('keeps a deleted message out of history but not out of the database', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner, 'gone');

    $this->actingAs($owner)->deleteJson(deleteUrl($workspace, $message))->assertOk();

    // Soft-deleted so thread replies keep their parent and a moderation
    // decision stays inspectable afterwards.
    expect(Message::withTrashed()->count())->toBe(1)
        ->and(Message::count())->toBe(0);
});

it('lets a workspace admin remove someone else message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $admin);

    $message = makeMessage($channel, $owner, 'something regrettable');

    $this->actingAs($admin)->deleteJson(deleteUrl($workspace, $message))->assertOk();

    expect($message->fresh()->trashed())->toBeTrue();
});

it('stops an ordinary member from deleting someone else message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $member);

    $message = makeMessage($channel, $owner, 'not yours to remove');

    $this->actingAs($member)->deleteJson(deleteUrl($workspace, $message))->assertForbidden();

    expect($message->fresh()->trashed())->toBeFalse();
});

it('audits a moderator deletion but not a self deletion', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    addToChannel($channel, $admin);

    $mine = makeMessage($channel, $admin, 'my own typo');
    $theirs = makeMessage($channel, $owner, 'moderated away');

    $this->actingAs($admin);
    $this->deleteJson(deleteUrl($workspace, $mine))->assertOk();
    $this->deleteJson(deleteUrl($workspace, $theirs))->assertOk();

    // Logging routine self-deletion too would bury the decisions anyone would
    // actually go looking for.
    $logs = AuditLog::where('action', AuditAction::MessageDeletedByAdmin)->get();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->auditable_id)->toBe($theirs->id)
        ->and($logs->first()->user_id)->toBe($admin->id);
});

it('does not carry the deleted body in the broadcast', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);
    $message = makeMessage($channel, $owner, 'the regrettable text');

    $this->actingAs($owner)->deleteJson(deleteUrl($workspace, $message));

    Event::assertDispatched(MessageDeleted::class, function (MessageDeleted $event) use ($message): bool {
        $payload = $event->broadcastWith();

        // Re-broadcasting the body would hand it to every subscriber at the
        // exact moment someone decided it should not exist.
        return $payload['id'] === $message->id
            && ! in_array('the regrettable text', $payload, true);
    });
});

it('decrements the channel message count on deletion', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $this->actingAs($owner);

    $url = route('channels.messages.store', ['workspace' => $workspace->slug, 'channel' => $channel->slug]);
    $id = $this->postJson($url, ['body' => 'counted'])->json('message.id');

    expect($channel->fresh()->message_count)->toBe(1);

    $this->deleteJson(route('messages.destroy', ['workspace' => $workspace->slug, 'message' => $id]))->assertOk();

    expect($channel->fresh()->message_count)->toBe(0);
});

it('refuses to delete a system message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $message = Message::factory()->inChannel($channel)->system('Ada joined the channel')->create();

    // System messages are the channel's record of what happened, not anyone's
    // to revise.
    $this->actingAs($owner)->deleteJson(deleteUrl($workspace, $message))->assertForbidden();
});
