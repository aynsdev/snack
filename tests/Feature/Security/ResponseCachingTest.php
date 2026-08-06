<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSharedCaching;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * No page this application serves may be held by a shared cache.
 *
 * Every document has the signed-in person inside it — Inertia embeds the whole
 * shared payload in `data-page`, and the page carries that session's CSRF token
 * besides. A cache that stores one and replays it puts the next visitor on
 * somebody else's account without a single request reaching the server, which
 * is precisely the failure no amount of server-side authorization can catch.
 *
 * See {@see PreventSharedCaching}.
 */
it('forbids storing an authenticated page, and keys it by session', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $response = $this->actingAs($owner)
        ->get(route('channels.show', [$workspace->slug, $channel->slug]))
        ->assertOk();

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('private');

    // Any cache that stores it regardless is at least keyed per session rather
    // than serving one entry to the whole company.
    expect($response->headers->all('Vary'))->toContain('Cookie');
});

/**
 * The login page is not "public HTML": it carries this visitor's CSRF token, and
 * a replayed copy of it is how a whole office ends up unable to sign in.
 */
it('forbids storing the pages served to guests', function () {
    User::factory()->create();

    $response = $this->get('/login')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

/**
 * Inertia's partial visits answer with JSON that is just as personal as the
 * document — the sidebar, the unread counts, the viewer's own abilities.
 */
it('forbids storing an Inertia partial visit', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    // The version has to match, or Inertia answers 409 to force a full reload
    // and never reaches the handler this is measuring.
    $version = app(HandleInertiaRequests::class)->version(request());

    $response = $this->actingAs($owner)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
        ->get(route('projects.index', $workspace->slug))
        ->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

/**
 * A response that asked for a lifetime of its own keeps it.
 *
 * Attachment URLs are signed, per-viewer authorized, and already private; their
 * one-hour window is what stops a timeline of images re-downloading every time
 * it is opened. Blanketing them with no-store would cost that for no safety.
 */
it('leaves a deliberately cached attachment alone', function () {
    Storage::fake('local');

    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $message = makeMessage($channel, $owner);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'original_name' => 'budget.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put($attachment->path, 'file contents');

    $url = URL::temporarySignedRoute(
        'files.show',
        now()->addHour(),
        ['attachment' => $attachment->id],
    );

    $response = $this->actingAs($owner)->get($url)->assertOk();

    expect($response->headers->get('Cache-Control'))
        ->toContain('max-age=3600')
        ->toContain('private')
        ->not->toContain('no-store');
});
