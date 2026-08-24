<?php

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * An attachment on a real message, with bytes actually on the fake disk.
 */
function attachedFile(
    Channel $channel,
    User $sender,
    string $contents = 'file contents',
): MessageAttachment {
    $message = makeMessage($channel, $sender);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'message_id' => $message->id,
        'uploaded_by' => $sender->id,
        'disk' => 'local',
        'original_name' => 'budget.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->path, $contents);

    return $attachment;
}

function signedShow(MessageAttachment $attachment, bool $inline = false): string
{
    return URL::temporarySignedRoute(
        'files.show',
        now()->addHour(),
        ['attachment' => $attachment->id, ...($inline ? ['inline' => 1] : [])],
    );
}

it('serves a file to someone who can read the message it hangs off', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $attachment = attachedFile($channel, $owner);

    actingAs($owner)
        ->get(signedShow($attachment))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('budget.pdf');
});

it('refuses a valid signature to someone who cannot read the message', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $private = makeChannel($workspace, ChannelType::Private);
    addToChannel($private, $owner);

    $outsider = joinWorkspace($workspace);
    $attachment = attachedFile($private, $owner);

    // The signature is fine. Authorization is re-derived from the message's
    // container on every hit, and a private channel answers 404.
    actingAs($outsider)
        ->get(signedShow($attachment))
        ->assertNotFound();
});

it('refuses a file to someone outside the workspace entirely', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $attachment = attachedFile($channel, $owner);
    $stranger = joinWorkspace(makeWorkspace());

    actingAs($stranger)
        ->get(signedShow($attachment))
        ->assertNotFound();
});

it('refuses an unsigned request', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $attachment = attachedFile($channel, $owner);

    actingAs($owner)
        ->get(route('files.show', $attachment))
        ->assertForbidden();
});

it('only lets the uploader fetch an upload that is not on a message yet', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $other = joinWorkspace($workspace);

    $attachment = MessageAttachment::factory()->orphaned()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
    ]);

    Storage::disk('local')->put($attachment->path, 'draft');

    actingAs($owner)->get(signedShow($attachment))->assertOk();
    actingAs($other)->get(signedShow($attachment))->assertNotFound();
});

/**
 * SVG renders inline — a logo is what the asset library is for, and a gallery of
 * grey placeholders where the artwork should be is not a library.
 *
 * What makes that safe is the headers, not the file: `sandbox` with no
 * `allow-scripts` token means nothing in the document executes even if somebody
 * navigates straight to the URL, and with no `allow-same-origin` token it has no
 * reach into this origin. This test is the guard on that pairing — inline
 * without the CSP is the stored XSS the disposition used to be preventing.
 */
it('serves an svg inline, sandboxed so nothing in it can run', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $message = makeMessage($channel, $owner);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'original_name' => 'logo.svg',
        'mime_type' => 'image/svg+xml',
    ]);

    Storage::disk('local')->put($attachment->path, '<svg onload="alert(1)"></svg>');

    $response = actingAs($owner)->get(signedShow($attachment, inline: true));

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Content-Type'))->toStartWith('image/svg+xml')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($response->headers->get('Content-Security-Policy'))->not->toContain('allow-scripts')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

/**
 * The inline door is still narrow. Anything that is not an image or PDF stays an
 * attachment however it is asked for — an HTML file rendered on this origin is
 * stored XSS that no sandbox on the *image* path would have covered.
 */
it('never serves a document inline, however it is asked for', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $message = makeMessage($channel, $owner);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'original_name' => 'notes.html',
        'mime_type' => 'text/html',
    ]);

    Storage::disk('local')->put($attachment->path, '<script>alert(1)</script>');

    $response = actingAs($owner)->get(signedShow($attachment, inline: true));

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('serves a pdf inline when asked, sandboxed like an image', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $attachment = attachedFile($channel, $owner, '%PDF-1.4 fake');

    $response = actingAs($owner)->get(signedShow($attachment, inline: true));

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('404s a thumbnail that has not been generated', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $attachment = attachedFile($channel, $owner);

    actingAs($owner)
        ->get(URL::temporarySignedRoute('files.thumbnail', now()->addHour(), [
            'attachment' => $attachment->id,
        ]))
        ->assertNotFound();
});
