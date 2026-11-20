<?php

use App\Actions\Workspaces\InviteWorkspaceMember;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;

/**
 * First-run setup.
 *
 * Snack is invitation-first, which leaves a fresh installation with no way in:
 * registration 404s because nobody holds an invitation, and the login form has
 * no account to authenticate. Setup is the one screen that may create a user
 * without an invitation, so the tests that matter most are the ones proving it
 * closes and stays closed.
 */
beforeEach(function () {
    Notification::fake();
});

test('the setup screen renders on a fresh installation', function () {
    $this->get(route('setup.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('setup/index'));
});

test('setup closes permanently once an account exists', function () {
    User::factory()->create();

    $this->get(route('setup.create'))->assertNotFound();

    $this->post(route('setup.store'), [
        'admin_name' => 'Intruder',
        'admin_email' => 'intruder@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'workspace_name' => 'Not Yours',
    ])->assertNotFound();

    expect(User::count())->toBe(1)
        ->and(Workspace::count())->toBe(0);
});

test('setup creates the owner, the workspace, and its default channels', function () {
    $this->post(route('setup.store'), [
        'admin_name' => 'Ada Lovelace',
        'admin_email' => 'ADA@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'workspace_name' => 'Snack HQ',
        'workspace_description' => 'Where the work happens.',
    ])->assertRedirect();

    $user = User::sole();
    $workspace = Workspace::sole();

    expect($user->name)->toBe('Ada Lovelace')
        // Normalized on the way in, so a capitalized address cannot become a
        // second account later.
        ->and($user->email)->toBe('ada@example.com')
        ->and($user->username)->not->toBeEmpty()
        // Nobody exists who could action a verification email, and every route
        // in the app sits behind the `verified` middleware.
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($workspace->name)->toBe('Snack HQ')
        ->and($workspace->owner_id)->toBe($user->id);

    expect($workspace->members()->where('user_id', $user->id)->value('role'))
        ->toBe(WorkspaceRole::Owner);

    $expected = array_column(config('snack.workspaces.default_channels'), 'name');

    expect($workspace->channels()->pluck('name')->all())
        ->toEqualCanonicalizing($expected);
});

test('setup signs the new owner in and lands them in the workspace', function () {
    $response = $this->post(route('setup.store'), [
        'admin_name' => 'Ada Lovelace',
        'admin_email' => 'ada@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'workspace_name' => 'Snack HQ',
    ]);

    $response->assertRedirect(route('workspaces.show', [
        'workspace' => Workspace::sole()->slug,
    ]));

    $this->assertAuthenticatedAs(User::sole());
});

test('setup issues the opening invitations', function () {
    $this->post(route('setup.store'), [
        'admin_name' => 'Ada Lovelace',
        'admin_email' => 'ada@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'workspace_name' => 'Snack HQ',
        // The middle row is blank: that is how the UI renders an empty field,
        // so it must be dropped rather than reported as a validation error.
        'invitations' => ['grace@example.com', '', 'ALAN@example.com'],
    ])->assertRedirect();

    expect(WorkspaceInvitation::pluck('email')->all())
        ->toEqualCanonicalizing(['grace@example.com', 'alan@example.com']);

    expect(WorkspaceInvitation::pluck('role')->unique()->values()->all())
        ->toBe([WorkspaceRole::Member]);
});

test('setup completes without inviting anyone', function () {
    $this->post(route('setup.store'), [
        'admin_name' => 'Ada Lovelace',
        'admin_email' => 'ada@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'workspace_name' => 'Snack HQ',
        'invitations' => ['', ''],
    ])->assertRedirect();

    expect(WorkspaceInvitation::count())->toBe(0)
        ->and(User::count())->toBe(1);
});

test('the owner cannot invite themselves', function () {
    $this->post(route('setup.store'), [
        'admin_name' => 'Ada Lovelace',
        'admin_email' => 'ada@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'workspace_name' => 'Snack HQ',
        // Different casing: the check must survive it, or the invitation would
        // be created and could never be accepted.
        'invitations' => ['ADA@example.com'],
    ])->assertSessionHasErrors('invitations.0');

    expect(User::count())->toBe(0);
});

test('the same address cannot be invited twice', function () {
    $this->post(route('setup.store'), [
        'admin_name' => 'Ada Lovelace',
        'admin_email' => 'ada@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'workspace_name' => 'Snack HQ',
        'invitations' => ['grace@example.com', 'GRACE@example.com'],
    ])->assertSessionHasErrors('invitations.0');
});

test('setup validates the account and the workspace', function (array $payload, string $field) {
    $this->post(route('setup.store'), $payload)->assertSessionHasErrors($field);

    expect(User::count())->toBe(0);
})->with([
    'no name' => [['admin_email' => 'a@example.com', 'workspace_name' => 'HQ'], 'admin_name'],
    'no email' => [['admin_name' => 'Ada', 'workspace_name' => 'HQ'], 'admin_email'],
    'bad email' => [['admin_name' => 'Ada', 'admin_email' => 'nope', 'workspace_name' => 'HQ'], 'admin_email'],
    'no workspace' => [['admin_name' => 'Ada', 'admin_email' => 'a@example.com'], 'workspace_name'],
    'short workspace' => [['admin_name' => 'Ada', 'admin_email' => 'a@example.com', 'workspace_name' => 'x'], 'workspace_name'],
    'no password' => [['admin_name' => 'Ada', 'admin_email' => 'a@example.com', 'workspace_name' => 'HQ'], 'password'],
]);

test('a mismatched password confirmation is rejected', function () {
    $this->post(route('setup.store'), [
        'admin_name' => 'Ada Lovelace',
        'admin_email' => 'ada@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'something-else',
        'workspace_name' => 'Snack HQ',
    ])->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
});

/**
 * The reason invitations are issued outside the transaction.
 *
 * Setup can never be re-run once the owner exists, so letting an unreachable
 * mail or queue backend roll the account back would strand the installation
 * with no way in at all — the exact failure the whole screen exists to prevent.
 */
test('a failing invitation does not cost the admin their account', function () {
    $this->mock(InviteWorkspaceMember::class, function (MockInterface $mock) {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('SMTP is down'));
    });

    $this->post(route('setup.store'), [
        'admin_name' => 'Ada Lovelace',
        'admin_email' => 'ada@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'workspace_name' => 'Snack HQ',
        'invitations' => ['grace@example.com'],
    ])->assertRedirect();

    expect(User::count())->toBe(1)
        ->and(Workspace::count())->toBe(1);

    // And the admin is told which addresses missed out, rather than the
    // failure being swallowed.
    expect(session('success'))->toContain('grace@example.com');
});

test('the landing page and login redirect to setup while the instance is empty', function () {
    $this->get('/')->assertRedirect(route('setup.create'));
    $this->get(route('login'))->assertRedirect(route('setup.create'));
});

test('the root redirects to workspaces and login is reachable once setup is done', function () {
    User::factory()->create();

    $this->get('/')->assertRedirect('/workspaces');

    $this->get(route('login'))->assertOk();
});

test('a guest opening the root ends up on the login screen', function () {
    User::factory()->create();

    $this->followingRedirects()
        ->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/login'));
});
