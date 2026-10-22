<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->withToken(mobileToken($this->user));
});

it('updates the profile', function () {
    $this->patchJson('/api/v1/me', ['name' => 'Ada Lovelace', 'email' => $this->user->email])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada Lovelace');
});

it('changes the password', function () {
    $this->putJson('/api/v1/me/password', [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertNoContent();

    expect(Hash::check('new-password-123', $this->user->fresh()->password))->toBeTrue();
});

it('sets a status', function () {
    $this->patchJson('/api/v1/me/status', ['emoji' => '🌴', 'message' => 'On leave', 'expires_in' => 'today'])
        ->assertOk()
        ->assertJsonPath('data.status_message', 'On leave');
});

it('sends a presence heartbeat', function () {
    $this->postJson('/api/v1/presence')->assertOk()->assertJsonStructure(['presence_status']);
});

it('deletes the account', function () {
    $this->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

    expect(User::find($this->user->id))->toBeNull();
});
