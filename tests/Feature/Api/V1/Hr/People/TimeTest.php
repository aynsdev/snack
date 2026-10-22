<?php

use App\Enums\WorkspaceRole;
use App\Models\TimeEntry;
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
    $this->employee = $this->member->employeeIn($this->workspace);
    $this->base = "/api/v1/w/{$this->workspace->slug}/hr/time";
});

it('clocks in and out with a geotag', function () {
    ($this->as)($this->member)
        ->postJson("{$this->base}/clock-in", ['latitude' => 14.5548, 'longitude' => 121.0245, 'accuracy' => 12])
        ->assertCreated()
        ->assertJsonPath('data.is_open', true)
        ->assertJsonPath('data.clock_in_location.latitude', 14.5548);

    $this->travel(8)->hours();

    ($this->as)($this->member)
        ->postJson("{$this->base}/clock-out")
        ->assertOk()
        ->assertJsonPath('data.is_open', false)
        ->assertJsonPath('data.minutes', 480);
});

it('refuses a second clock-in', function () {
    ($this->as)($this->member)->postJson("{$this->base}/clock-in")->assertCreated();

    ($this->as)($this->member)
        ->postJson("{$this->base}/clock-in")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('clock');

    expect(TimeEntry::query()->count())->toBe(1);
});

it('shows your own timesheet', function () {
    ($this->as)($this->member)->postJson("{$this->base}/clock-in")->assertCreated();

    ($this->as)($this->member)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.has_employee', true)
        ->assertJsonPath('data.filters.scope', 'mine')
        ->assertJsonPath('data.can_manage', false)
        ->assertJsonPath('data.employees', []);
});

it('shows HR the team timesheet', function () {
    ($this->as)($this->member)->postJson("{$this->base}/clock-in")->assertCreated();

    ($this->as)($this->owner)
        ->getJson("{$this->base}?scope=team&employee={$this->employee->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.filters.scope', 'team')
        ->assertJsonPath('data.can_manage', true);
});

it('lets HR correct and delete a time entry', function () {
    ($this->as)($this->member)->postJson("{$this->base}/clock-in")->assertCreated();
    $entry = TimeEntry::query()->firstOrFail();

    ($this->as)($this->owner)
        ->patchJson("{$this->base}/{$entry->id}", [
            'clock_in_at' => now()->subHours(9)->toIso8601String(),
            'clock_out_at' => now()->subHour()->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('data.minutes', 480);

    ($this->as)($this->member)
        ->deleteJson("{$this->base}/{$entry->id}")
        ->assertForbidden();

    ($this->as)($this->owner)
        ->deleteJson("{$this->base}/{$entry->id}")
        ->assertNoContent();

    expect(TimeEntry::query()->count())->toBe(0);
});
