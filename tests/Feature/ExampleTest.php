<?php

use App\Models\User;

test('the root redirects to workspaces', function () {
    // The app has no landing page of its own; the root only forwards. With no
    // accounts it goes to first-run setup instead. See
    // tests/Feature/Setup/InstanceSetupTest.php.
    User::factory()->create();

    $this->get(route('home'))->assertRedirect('/workspaces');
});
