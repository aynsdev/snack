<?php

use App\Enums\WorkspaceRole;
use App\Models\MessageAttachment;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

it('attaches a file to a card in one step', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    $this->actingAs($owner)
        ->post(route('tasks.attachments.store', [$workspace->slug, $task->id]), [
            'file' => UploadedFile::fake()->create('spec.pdf', 120, 'application/pdf'),
        ])
        ->assertCreated()
        ->assertJsonPath('attachment.name', 'spec.pdf');

    $attachment = MessageAttachment::query()->sole();

    // The card already existed when the file was chosen, so there is nothing to
    // claim afterwards and no window in which this looks abandoned.
    expect($attachment->task_id)->toBe($task->id)
        ->and($attachment->message_id)->toBeNull();

    Storage::disk('local')->assertExists($attachment->path);
});

it('refuses a file from somebody who is only watching the board', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);
    $onlooker = joinWorkspace($workspace);

    $this->actingAs($onlooker)
        ->post(route('tasks.attachments.store', [$workspace->slug, $task->id]), [
            'file' => UploadedFile::fake()->create('spec.pdf', 10, 'application/pdf'),
        ])
        ->assertForbidden();

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('serves a card’s file to whoever may read the board, and nobody else', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => null,
        'task_id' => $task->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'original_name' => 'spec.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->path, 'the spec');

    $url = URL::temporarySignedRoute(
        'files.show',
        now()->addHour(),
        ['attachment' => $attachment->id],
        absolute: false,
    );

    // A board is readable by the whole workspace, so its files are too — a
    // colleague who is not on the project can still open the spec.
    $colleague = joinWorkspace($workspace);

    $this->actingAs($colleague)->get($url)->assertOk();

    /*
     * The signature is not the authorization. A guest is confined to what they
     * were added to, so the same valid link is a 404 for them — and it stays one
     * however it reached them.
     */
    $guest = joinWorkspace($workspace, role: WorkspaceRole::Guest);

    $this->actingAs($guest)->get($url)->assertNotFound();
});

it('lets the uploader or a lead remove a card’s file, and nobody else', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    $uploader = joinWorkspace($workspace);
    addToProject($project, $uploader);
    $colleague = joinWorkspace($workspace);
    addToProject($project, $colleague);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => null,
        'task_id' => $task->id,
        'uploaded_by' => $uploader->id,
        'disk' => 'local',
    ]);

    Storage::disk('local')->put($attachment->path, 'bytes');

    $this->actingAs($colleague)
        ->deleteJson(route('tasks.attachments.destroy', [$workspace->slug, $task->id, $attachment->id]))
        ->assertForbidden();

    $this->actingAs($uploader)
        ->deleteJson(route('tasks.attachments.destroy', [$workspace->slug, $task->id, $attachment->id]))
        ->assertOk();

    // The row and the bytes go together: an object nothing points at would never
    // be found again.
    expect(MessageAttachment::find($attachment->id))->toBeNull();
    Storage::disk('local')->assertMissing($attachment->path);
});

it('does not treat a card’s file as abandoned tray state', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => null,
        'task_id' => $task->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'created_at' => now()->subWeek(),
    ]);

    Storage::disk('local')->put($attachment->path, 'bytes');

    $this->artisan('snack:prune-uploads')->assertSuccessful();

    // A file on a card has no message either, so the sweep that collects
    // never-sent uploads would otherwise delete every one of them within a day.
    expect(MessageAttachment::find($attachment->id))->not->toBeNull();

    // And it is not the composer's to discard, either.
    $this->actingAs($owner)
        ->deleteJson(route('uploads.destroy', [$workspace->slug, $attachment->id]))
        ->assertNotFound();
});

it('refuses to let one file belong to both a message and a card', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    $message = makeMessage($channel, $owner);
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    // The database refuses it, because "who may read this file" must not have
    // two answers. See the attachments migration.
    expect(fn () => MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => $message->id,
        'task_id' => $task->id,
        'uploaded_by' => $owner->id,
    ]))->toThrow(QueryException::class);
});

it('takes a card’s files with it when the card is deleted', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner, ['reporter_id' => $owner->id]);

    MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => null,
        'task_id' => $task->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
    ]);

    $this->actingAs($owner)
        ->deleteJson(route('tasks.destroy', [$workspace->slug, $task->id]))
        ->assertOk();

    expect(MessageAttachment::query()->count())->toBe(0);
});

it('sends a card’s files with the card', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $project = makeProject($workspace, $owner);
    $task = makeTask($project, $owner);

    MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => null,
        'task_id' => $task->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'original_name' => 'spec.pdf',
    ]);

    $this->actingAs($owner)
        ->getJson(route('tasks.show', [$workspace->slug, $task->id]))
        ->assertOk()
        ->assertJsonPath('task.attachments.0.name', 'spec.pdf');
});
