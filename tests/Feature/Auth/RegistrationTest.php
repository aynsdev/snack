<?php

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\RegistrationGate;
use Laravel\Fortify\Features;

/**
 * Snack is invitation-first.
 *
 * With `snack.allow_public_registration` off — the default for an internal
 * deployment — the sign-up form is not merely hidden, it 404s, and posting
 * directly to the endpoint is rejected by CreateNewUser.
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('the registration screen is hidden when registration is closed', function () {
    config(['snack.allow_public_registration' => false]);

    $this->get(route('register'))->assertNotFound();
});

test('registration is refused without an invitation', function () {
    config(['snack.allow_public_registration' => false]);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('registration screen can be rendered when public registration is enabled', function () {
    config(['snack.allow_public_registration' => true]);

    $this->get(route('register'))->assertOk();
});

test('new users can register when public registration is enabled', function () {
    config(['snack.allow_public_registration' => true]);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('an invited visitor can register even when registration is closed', function () {
    config(['snack.allow_public_registration' => false]);

    [$workspace] = makeWorkspaceWithOwner();

    $invitation = WorkspaceInvitation::factory()
        ->forEmail('invited@snack.test')
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    // Opening the invitation link is what stashes the token in the session.
    $this->get(route('invitations.show', ['token' => $invitation->plainToken]))
        ->assertOk();

    $this->get(route('register'))->assertOk();

    // Registering is a detour on the way to joining, so the new account is
    // returned to the invitation instead of the empty workspace picker.
    $this->post(route('register.store'), [
        'name' => 'Invited Person',
        'email' => 'invited@snack.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('invitations.show', ['token' => $invitation->plainToken]));

    $this->assertAuthenticated();
});

test('an invited visitor can join straight after registering', function () {
    config(['snack.allow_public_registration' => false]);

    [$workspace] = makeWorkspaceWithOwner();

    $invitation = WorkspaceInvitation::factory()
        ->forEmail('invited@snack.test')
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    $this->get(route('invitations.show', ['token' => $invitation->plainToken]));

    $this->post(route('register.store'), [
        'name' => 'Invited Person',
        'email' => 'invited@snack.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    // The invitation now recognises the freshly created account and offers to
    // join rather than asking them to sign up again.
    $this->get(route('invitations.show', ['token' => $invitation->plainToken]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/invitation')
            ->where('isAuthenticated', true)
            ->where('emailMatches', true));

    $this->post(route('invitations.accept', ['token' => $invitation->plainToken]))
        ->assertRedirect(route('workspaces.show', $workspace));

    expect(User::where('email', 'invited@snack.test')->sole()->belongsToWorkspace($workspace))
        ->toBeTrue();
});

test('an invited visitor cannot register with a different email address', function () {
    config(['snack.allow_public_registration' => false]);

    [$workspace] = makeWorkspaceWithOwner();

    $invitation = WorkspaceInvitation::factory()
        ->forEmail('invited@snack.test')
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    $this->get(route('invitations.show', ['token' => $invitation->plainToken]));

    // A forwarded invitation link must not onboard whoever received it.
    $this->post(route('register.store'), [
        'name' => 'Someone Else',
        'email' => 'someone.else@snack.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('registration derives a unique username', function () {
    config(['snack.allow_public_registration' => true]);

    foreach (['first@snack.test', 'second@snack.test'] as $email) {
        $this->post(route('register.store'), [
            'name' => 'Ada Lovelace',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        auth()->logout();
    }

    /*
     * Ordered explicitly. Postgres makes no promise about the order of an
     * unordered select, and the assertion below is specifically that the *first*
     * registration got the undecorated name — so without this the test passes or
     * fails depending on how the table happens to be laid out, which changes as
     * unrelated tests are added ahead of it.
     */
    $usernames = User::whereIn('email', ['first@snack.test', 'second@snack.test'])
        ->orderBy('id')
        ->pluck('username');

    expect($usernames)->toHaveCount(2)
        ->and($usernames->unique())->toHaveCount(2)
        ->and($usernames->first())->toBe('ada-lovelace');
});

test('the registration gate honours an expired invitation', function () {
    config(['snack.allow_public_registration' => false]);

    [$workspace] = makeWorkspaceWithOwner();

    $invitation = WorkspaceInvitation::factory()
        ->forEmail('late@snack.test')
        ->expired()
        ->create(['workspace_id' => $workspace->id, 'invited_by' => $workspace->owner_id]);

    session([RegistrationGate::SESSION_KEY => $invitation->plainToken]);

    expect(app(RegistrationGate::class)->isOpen())->toBeFalse();
});
