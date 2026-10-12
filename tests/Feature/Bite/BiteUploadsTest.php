<?php

use App\Ai\BiteUploads;
use App\Enums\BiteCapability;
use App\Enums\WorkspaceRole;
use App\Models\BiteSetting;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;

/**
 * Handing Bite a file, rather than asking it to go and find one.
 *
 * `read_file` covers the second: the model hears a name, calls `find_files`,
 * and reads what comes back. This covers the first — somebody drags a
 * screenshot into the composer and types "@bite what is wrong here?" — where
 * there is no name to search for and the file *is* the question.
 *
 * The property being protected is that Bite is never left answering about a
 * file it was not shown. Either the contents reach the provider, or the prompt
 * says plainly that they did not.
 */
beforeEach(function () {
    Storage::fake('local');

    config()->set('snack.bite.enabled', true);
    config()->set('ai.providers.anthropic.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    addToChannel($this->channel, $this->ada);

    $this->uploads = app(BiteUploads::class);
});

/** A message with one attachment of the given shape. */
function messageWithFile(array $state = [], string $body = '@bite what is this?'): Message
{
    $message = makeMessage(test()->channel, test()->ada, $body);

    MessageAttachment::factory()->create([
        'workspace_id' => test()->workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => test()->ada->id,
        'disk' => 'local',
        ...$state,
    ]);

    return $message->fresh()->load('attachments');
}

it('says nothing about files when none were attached', function () {
    $message = makeMessage($this->channel, $this->ada, '@bite hello');

    expect($this->uploads->describe($message))->toBe('')
        ->and($this->uploads->attachmentsFor($message))->toBe([]);
});

/**
 * An image goes to the provider as bytes, which works on every disk, including
 * a local one that cannot sign URLs.
 */
it('attaches an image for the model to look at', function () {
    $message = messageWithFile([
        'path' => 'attachments/shot.png',
        'original_name' => 'shot.png',
        'mime_type' => 'image/png',
    ]);

    Storage::disk('local')->put('attachments/shot.png', 'not-really-a-png');

    expect($this->uploads->attachmentsFor($message))->toHaveCount(1)
        ->and($this->uploads->describe($message))->toContain('shot.png');
});

/**
 * Text is read into the prompt instead of attached: it is cheaper, and it is
 * the one shape a language model cannot misread as a picture.
 */
it('reads a text file into the prompt rather than attaching it', function () {
    Storage::disk('local')->put('attachments/notes.md', 'The staging host is stage-2.');

    $message = messageWithFile([
        'path' => 'attachments/notes.md',
        'original_name' => 'notes.md',
        'mime_type' => 'text/markdown',
    ]);

    expect($this->uploads->describe($message))->toContain('The staging host is stage-2.')
        ->and($this->uploads->attachmentsFor($message))->toBe([]);
});

it('truncates a very long text file loudly', function () {
    config()->set('snack.bite.files.max_bytes', 20);

    Storage::disk('local')->put('attachments/big.csv', str_repeat('a', 100));

    $message = messageWithFile([
        'path' => 'attachments/big.csv',
        'original_name' => 'big.csv',
        'mime_type' => 'text/csv',
    ]);

    expect($this->uploads->describe($message))->toContain('Truncated');
});

/**
 * A PDF goes as a short-lived signed URL the provider fetches itself — see
 * App\Ai\Tools\ReadFile.
 */
it('hands a PDF over as a temporary link', function () {
    $message = messageWithFile([
        'path' => 'attachments/invoice.pdf',
        'original_name' => 'invoice.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $attachments = $this->uploads->attachmentsFor($message);

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->name)->toBe('invoice.pdf')
        ->and($attachments[0]->url)->toContain('invoice.pdf')
        ->and($this->uploads->describe($message))->toContain('invoice.pdf');
});

/**
 * And when storage cannot serve it at all, the file is still *named*. The
 * answer has to be "you sent me the invoice and I cannot open it here" rather
 * than one invented about a document nobody read.
 */
it('names a PDF it cannot fetch instead of pretending it read it', function () {
    $message = messageWithFile([
        'disk' => 'no-such-disk',
        'path' => 'attachments/invoice.pdf',
        'original_name' => 'invoice.pdf',
        'mime_type' => 'application/pdf',
    ]);

    expect($this->uploads->attachmentsFor($message))->toBe([])
        ->and($this->uploads->describe($message))
        ->toContain('invoice.pdf')
        ->toContain('PDF');
});

it('names a file it cannot read at all', function () {
    $message = messageWithFile([
        'path' => 'attachments/archive.zip',
        'original_name' => 'archive.zip',
        'mime_type' => 'application/zip',
    ]);

    expect($this->uploads->describe($message))
        ->toContain('archive.zip')
        ->toContain('not been shown its contents');
});

/**
 * Four is the cap, and the rest are counted rather than dropped in silence —
 * an assistant that quietly ignored half of what it was sent is one whose
 * answers cannot be trusted to be about everything.
 */
it('caps how many files it takes and says how many it left', function () {
    $message = makeMessage($this->channel, $this->ada, '@bite look at these');

    foreach (range(1, 6) as $index) {
        MessageAttachment::factory()->image()->create([
            'workspace_id' => $this->workspace->id,
            'message_id' => $message->id,
            'uploaded_by' => $this->ada->id,
            'disk' => 'local',
            'path' => "attachments/shot-{$index}.png",
            'original_name' => "shot-{$index}.png",
        ]);
    }

    $described = $this->uploads->describe($message->fresh()->load('attachments'));

    expect($described)->toContain('2 further file(s)')
        ->and($described)->toContain('shot-1.png')
        ->and($described)->not->toContain('shot-6.png');
});

/**
 * The admin switch covers this door too. "Read files" switched off means Bite
 * may not read this workspace's files, and a file arriving attached to a
 * question is still one of this workspace's files.
 */
it('is bound by the read files capability', function () {
    BiteSetting::factory()
        ->forWorkspace($this->workspace)
        ->allowing([BiteCapability::SearchMessages])
        ->create();

    expect(BiteSetting::forWorkspace($this->workspace->fresh())->allows(BiteCapability::ReadFile))
        ->toBeFalse();
});
