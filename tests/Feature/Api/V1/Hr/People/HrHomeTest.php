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
    $this->employee = $this->member->employeeIn($this->workspace);
    $this->base = "/api/v1/w/{$this->workspace->slug}/hr";
});

it('shows the HR home', function () {
    ($this->as)($this->member)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.employee.id', $this->employee->id)
        ->assertJsonPath('data.clock.open_entry', null)
        ->assertJsonPath('data.stats', null)
        ->assertJsonPath('data.can_manage', false)
        ->assertJsonStructure(['data' => ['balances', 'my_requests', 'announcements', 'upcoming_holidays', 'out_today', 'my_tasks']]);
});

it('gives people managers the HR stats on the home', function () {
    ($this->as)($this->owner)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.can_manage', true)
        ->assertJsonPath('data.stats.headcount', 2);
});

it('hides HR from a non-member', function () {
    ($this->as)(User::factory()->create())
        ->getJson($this->base)
        ->assertNotFound();
});

it('shows the HR analytics dashboard to people managers only', function () {
    ($this->as)($this->owner)
        ->getJson("{$this->base}/dashboard")
        ->assertOk()
        ->assertJsonStructure(['data' => ['kpis', 'departments', 'attendance', 'pending_leave']]);

    ($this->as)($this->member)
        ->getJson("{$this->base}/dashboard")
        ->assertForbidden();
});

it('reports the header clock state', function () {
    ($this->as)($this->member)
        ->getJson("{$this->base}/clock")
        ->assertOk()
        ->assertJsonPath('data.employee_id', $this->employee->id)
        ->assertJsonPath('data.open_entry', null)
        ->assertJsonPath('data.require_location', false);

    ($this->as)($this->member)->postJson("{$this->base}/time/clock-in")->assertCreated();

    ($this->as)($this->member)
        ->getJson("{$this->base}/clock")
        ->assertOk()
        ->assertJsonPath('data.open_entry.id', $this->employee->timeEntries()->value('id'));
});

it('has no clock for somebody whose employment ended', function () {
    $this->employee->update(['status' => 'terminated']);

    ($this->as)($this->member)
        ->getJson("{$this->base}/clock")
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('shows the org chart to every member', function () {
    $this->employee->update(['manager_id' => $this->owner->employeeIn($this->workspace)->id]);

    ($this->as)($this->member)
        ->getJson("{$this->base}/org-chart")
        ->assertOk()
        ->assertJsonCount(2, 'data.people')
        ->assertJsonPath('data.own_employee_id', $this->employee->id);
});
