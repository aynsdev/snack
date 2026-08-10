<?php

use App\Actions\Profile\UpdateAvatar;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Moving Snack's files onto object storage — DigitalOcean Spaces in production,
 * the local disks in development.
 *
 * Two settings do it: `snack.uploads.disk` for everything served through the
 * application, and `snack.avatars.disk` for the two things served by URL. What
 * these tests hold down is that *both sides* of each setting agree — the code
 * that writes a file and the code that later says where it is.
 */
it('builds an avatar url from the configured disk', function () {
    Storage::fake('cdn', ['url' => 'https://cdn.example.test']);
    config(['snack.avatars.disk' => 'cdn']);

    $user = User::factory()->create();

    app(UpdateAvatar::class)->handle(
        $user,
        UploadedFile::fake()->image('face.png', 600, 600),
    );

    $user->refresh();

    /*
     * The regression this covers: the write side has always read this setting
     * and the read side hardcoded `public`, so pointing avatars at a bucket
     * uploaded the bytes there and then asked the browser for them from
     * /storage, where nothing was — every face in the product silently broken.
     */
    expect($user->avatar_path)->not->toBeNull()
        ->and($user->avatarUrl())->toStartWith('https://cdn.example.test');

    Storage::disk('cdn')->assertExists($user->avatar_path);
});

it('builds a workspace logo url from the same configured disk', function () {
    Storage::fake('cdn', ['url' => 'https://cdn.example.test']);
    config(['snack.avatars.disk' => 'cdn']);

    $workspace = Workspace::factory()->create(['logo_path' => 'logos/acme.png']);

    expect($workspace->logoUrl())->toStartWith('https://cdn.example.test');
});

it('keeps both spaces disks on one s3 driver with opposite visibility', function () {
    $private = config('filesystems.disks.spaces');
    $public = config('filesystems.disks.spaces_public');

    expect($private['driver'])->toBe('s3')
        ->and($public['driver'])->toBe('s3')
        /*
         * The private one is where attachments and library assets go, and
         * nothing in it is ever served by URL — an object in there reachable
         * without the application would bypass every policy in the app.
         */
        ->and($private['visibility'])->toBe('private')
        ->and($public['visibility'])->toBe('public')
        /*
         * Spaces addresses buckets virtual-host style, which is the SDK's own
         * default. Path style produces URLs Spaces answers with a 403.
         */
        ->and($private['use_path_style_endpoint'])->toBeFalse();
});

it('falls back to the private bucket when no public bucket is named', function () {
    // The single-bucket deployment: public avatars sit in the private bucket
    // under per-object ACLs rather than in a second bucket of their own.
    expect(config('filesystems.disks.spaces_public.bucket'))
        ->toBe(config('filesystems.disks.spaces.bucket'));
});
