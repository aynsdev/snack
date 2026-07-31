<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('public');
});

it('stores a re-encoded, square avatar', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('profile.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('me.jpg', 1200, 800),
        ])
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull()
        ->and($user->avatar_path)->toStartWith("avatars/{$user->id}/");

    Storage::disk('public')->assertExists($user->avatar_path);

    $stored = Storage::disk('public')->get($user->avatar_path);
    $size = getimagesizefromstring($stored);

    // Centre-cropped to a square and bounded by the configured dimension.
    expect($size[0])->toBe($size[1])
        ->and($size[0])->toBeLessThanOrEqual(config('snack.avatars.dimension'));
});

it('exposes the new avatar through the shared profile prop', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('profile.avatar.store'), [
        'avatar' => UploadedFile::fake()->image('me.png', 300, 300),
    ]);

    actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.profile.avatar_url', fn ($url) => $url !== null),
        );
});

it('deletes the previous file when a new one replaces it', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('profile.avatar.store'), [
        'avatar' => UploadedFile::fake()->image('first.png', 200, 200),
    ]);

    $first = $user->refresh()->avatar_path;

    actingAs($user)->post(route('profile.avatar.store'), [
        'avatar' => UploadedFile::fake()->image('second.png', 200, 200),
    ]);

    $second = $user->refresh()->avatar_path;

    expect($second)->not->toBe($first);

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

it('removes the avatar and its file', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('profile.avatar.store'), [
        'avatar' => UploadedFile::fake()->image('me.png', 200, 200),
    ]);

    $path = $user->refresh()->avatar_path;

    actingAs($user)
        ->delete(route('profile.avatar.destroy'))
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('refuses a file that is not an image', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('profile.avatar.store'), [
            'avatar' => UploadedFile::fake()->create('resume.pdf', 20, 'application/pdf'),
        ])
        ->assertSessionHasErrors('avatar');

    expect($user->refresh()->avatar_path)->toBeNull();
});

it('refuses an svg, which is a document that can carry script', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('profile.avatar.store'), [
            'avatar' => UploadedFile::fake()->create(
                'logo.svg',
                2,
                'image/svg+xml',
            ),
        ])
        ->assertSessionHasErrors('avatar');
});

it('refuses an image above the size ceiling', function () {
    config()->set('snack.avatars.max_size_kb', 10);

    $user = User::factory()->create();

    actingAs($user)
        ->post(route('profile.avatar.store'), [
            'avatar' => UploadedFile::fake()->create('huge.jpg', 5_000, 'image/jpeg'),
        ])
        ->assertSessionHasErrors('avatar');
});

it('requires a signed-in user', function () {
    $this->post(route('profile.avatar.store'), [
        'avatar' => UploadedFile::fake()->image('me.png'),
    ])->assertRedirect(route('login'));
});

it('does not upscale a small source', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('profile.avatar.store'), [
        'avatar' => UploadedFile::fake()->image('tiny.png', 64, 64),
    ]);

    $size = getimagesizefromstring(
        Storage::disk('public')->get($user->refresh()->avatar_path),
    );

    expect($size[0])->toBe(64);
});
