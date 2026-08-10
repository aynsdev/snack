<?php

use App\Models\User;

it('updates the signed-in users status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('status.update'), [
            'emoji' => '🏡',
            'message' => 'Working from home',
            'expires_in' => 'four_hours',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh())
        ->status_emoji->toBe('🏡')
        ->status_message->toBe('Working from home')
        ->status_expires_at->not->toBeNull();
});

it('clears the signed-in users status', function () {
    $user = User::factory()->create([
        'status_emoji' => '🏡',
        'status_message' => 'Working from home',
        'status_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->patch(route('status.update'), [
            'message' => '',
            'expires_in' => 'never',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh())
        ->status_emoji->toBeNull()
        ->status_message->toBeNull()
        ->status_expires_at->toBeNull();
});

it('validates status input', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('status.update'), [
            'message' => str_repeat('a', 81),
            'expires_in' => 'tomorrow',
        ])
        ->assertInvalid(['message', 'expires_in']);
});

it('requires authentication to update a status', function () {
    $this->patch(route('status.update'), [
        'message' => 'Unavailable',
        'expires_in' => 'one_hour',
    ])->assertRedirect(route('login'));
});
