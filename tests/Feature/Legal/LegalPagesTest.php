<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The App Store and Play Store listings link to these pages, and the reviewers
 * who open them are never signed in — on an installation that may not even
 * have been set up yet.
 */
test('legal pages are public', function (string $route, string $component) {
    User::factory()->create();

    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'terms' => ['legal.terms', 'legal/terms'],
    'privacy' => ['legal.privacy', 'legal/privacy'],
    'delete account' => ['legal.delete-account', 'legal/delete-account'],
]);

test('legal pages are reachable before first-run setup', function () {
    $this->get(route('legal.privacy'))->assertOk();
});
