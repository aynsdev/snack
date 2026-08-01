<?php

use App\Models\User;

test('returns a successful response', function () {
    // The landing page is only reachable on an initialized installation; with
    // no accounts it redirects to first-run setup. See
    // tests/Feature/Setup/InstanceSetupTest.php.
    User::factory()->create();

    $response = $this->get(route('home'));

    $response->assertOk();
});
