<?php

use App\Enums\WorkspaceRole;
use App\Models\User;

beforeEach(function () {
    // Guards cache the resolved user, so a token for somebody else needs
    // them forgotten first.
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->base = "/api/v1/w/{$this->workspace->slug}/hr";
});

it('lists holidays for every member', function () {
    $this->workspace->holidays()->create(['name' => 'Founders day', 'date' => now()->addMonth()->toDateString()]);

    ($this->as)($this->member)
        ->getJson("{$this->base}/holidays")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Founders day')
        ->assertJsonPath('can_manage', false);
});

it('manages holidays', function () {
    $id = ($this->as)($this->owner)
        ->postJson("{$this->base}/holidays", ['name' => 'New Year', 'date' => '2027-01-01', 'is_recurring' => true])
        ->assertCreated()
        ->assertJsonPath('data.is_recurring', true)
        ->json('data.id');

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/holidays/{$id}", ['name' => 'New Year\'s Day', 'date' => '2027-01-01'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Year\'s Day')
        ->assertJsonPath('data.is_recurring', false);

    ($this->as)($this->owner)
        ->deleteJson("{$this->base}/holidays/{$id}")
        ->assertNoContent();
});

it('keeps holiday management from ordinary members', function () {
    ($this->as)($this->member)
        ->postJson("{$this->base}/holidays", ['name' => 'Mine', 'date' => '2027-01-01'])
        ->assertForbidden();
});

it('manages shifts', function () {
    $shift = [
        'name' => 'Day',
        'starts_at' => '09:00',
        'ends_at' => '18:00',
        'grace_minutes' => 15,
        'days' => [5, 1, 2, 3, 4],
        'is_default' => true,
    ];

    $id = ($this->as)($this->owner)
        ->postJson("{$this->base}/shifts", $shift)
        ->assertCreated()
        ->assertJsonPath('data.days', [1, 2, 3, 4, 5])
        ->assertJsonPath('data.is_default', true)
        ->json('data.id');

    ($this->as)($this->member)
        ->getJson("{$this->base}/shifts")
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/shifts/{$id}", [...$shift, 'name' => 'Office', 'grace_minutes' => 10])
        ->assertOk()
        ->assertJsonPath('data.name', 'Office');

    ($this->as)($this->member)
        ->deleteJson("{$this->base}/shifts/{$id}")
        ->assertForbidden();

    ($this->as)($this->owner)
        ->deleteJson("{$this->base}/shifts/{$id}")
        ->assertNoContent();
});
