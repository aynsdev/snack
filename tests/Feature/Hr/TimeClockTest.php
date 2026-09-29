<?php

use App\Enums\WorkspaceRole;
use App\Models\TimeEntry;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);
    $this->base = "/w/{$this->workspace->slug}/hr/time";

    // An office in Makati with a 200 m fence.
    $this->office = ['latitude' => 14.5547, 'longitude' => 121.0244];
});

function setHrSettings($workspace, array $hr): void
{
    $workspace->update(['settings' => [...($workspace->settings ?? []), 'hr' => $hr]]);
}

it('clocks in and out with a geotag', function () {
    setHrSettings($this->workspace, ['geofence' => [...$this->office, 'radius_meters' => 200, 'enforced' => false]]);

    $this->actingAs($this->member)
        ->post("{$this->base}/clock-in", ['latitude' => 14.5548, 'longitude' => 121.0245, 'accuracy' => 12])
        ->assertSessionHasNoErrors();

    $entry = TimeEntry::query()->firstOrFail();

    expect($entry->isOpen())->toBeTrue()
        ->and($entry->clock_in_within_geofence)->toBeTrue()
        ->and((float) $entry->clock_in_latitude)->toBe(14.5548);

    $this->travel(8)->hours();

    $this->actingAs($this->member)
        ->post("{$this->base}/clock-out", ['latitude' => 14.60, 'longitude' => 121.10])
        ->assertSessionHasNoErrors();

    $entry->refresh();

    expect($entry->isOpen())->toBeFalse()
        ->and($entry->clock_out_within_geofence)->toBeFalse()
        ->and($entry->minutesWorked())->toBe(480);
});

it('refuses a second clock-in', function () {
    $this->actingAs($this->member)->post("{$this->base}/clock-in")->assertSessionHasNoErrors();
    $this->actingAs($this->member)->post("{$this->base}/clock-in")->assertSessionHasErrors('clock');

    expect(TimeEntry::query()->count())->toBe(1);
});

it('refuses to clock out when not clocked in', function () {
    $this->actingAs($this->member)->post("{$this->base}/clock-out")->assertSessionHasErrors('clock');
});

it('requires a location when the workspace says so', function () {
    setHrSettings($this->workspace, ['require_location' => true]);

    $this->actingAs($this->member)->post("{$this->base}/clock-in")->assertSessionHasErrors('location');
    $this->actingAs($this->member)
        ->post("{$this->base}/clock-in", ['latitude' => 1, 'longitude' => 1])
        ->assertSessionHasNoErrors();
});

it('refuses clocking from outside an enforced geofence', function () {
    setHrSettings($this->workspace, ['geofence' => [...$this->office, 'radius_meters' => 200, 'enforced' => true]]);

    $this->actingAs($this->member)
        ->post("{$this->base}/clock-in", ['latitude' => 14.60, 'longitude' => 121.10])
        ->assertSessionHasErrors('location');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('validates coordinates', function () {
    $this->actingAs($this->member)
        ->post("{$this->base}/clock-in", ['latitude' => 120, 'longitude' => 10])
        ->assertSessionHasErrors('latitude');
});

it('shows members only their own timesheet', function () {
    $colleague = joinWorkspace($this->workspace)->employeeIn($this->workspace);
    $colleague->timeEntries()->create(['workspace_id' => $this->workspace->id, 'clock_in_at' => now()]);
    $this->employee->timeEntries()->create(['workspace_id' => $this->workspace->id, 'clock_in_at' => now()]);

    $this->actingAs($this->member)
        ->get("{$this->base}?scope=team")
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/time')
            ->where('filters.scope', 'mine')
            ->has('entries', 1));

    $this->actingAs($this->owner)
        ->get("{$this->base}?scope=team")
        ->assertInertia(fn (Assert $page) => $page->has('entries', 2));
});

it('lets HR correct a time entry', function () {
    $entry = $this->employee->timeEntries()->create(['workspace_id' => $this->workspace->id, 'clock_in_at' => now()]);

    $this->actingAs($this->member)
        ->patch("{$this->base}/{$entry->id}", ['clock_in_at' => now()->subHour()->toIso8601String()])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->patch("{$this->base}/{$entry->id}", [
            'clock_in_at' => '2026-09-28T09:00:00+00:00',
            'clock_out_at' => '2026-09-28T17:30:00+00:00',
        ])
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->minutesWorked())->toBe(510);
});

it('saves the geofence in HR settings', function () {
    $this->actingAs($this->owner)
        ->patch("/w/{$this->workspace->slug}/hr/settings", [
            'require_location' => true,
            'geofence' => [...$this->office, 'radius_meters' => 150, 'enforced' => true],
        ])
        ->assertSessionHasNoErrors();

    $workspace = $this->workspace->fresh();

    expect($workspace->setting('hr.geofence.radius_meters'))->toBe(150)
        ->and($workspace->setting('hr.require_location'))->toBeTrue();

    $this->actingAs($this->member)
        ->patch("/w/{$this->workspace->slug}/hr/settings", ['require_location' => false])
        ->assertForbidden();
});

it('shares the header clock on every workspace page', function () {
    $this->actingAs($this->member)
        ->get("/w/{$this->workspace->slug}/hr/holidays")
        ->assertInertia(fn (Assert $page) => $page
            ->where('clock.employee_id', $this->employee->id)
            ->where('clock.open_entry', null)
            ->where('clock.require_location', false));

    $this->actingAs($this->member)->post("{$this->base}/clock-in")->assertSessionHasNoErrors();

    $entry = TimeEntry::query()->firstOrFail();

    $this->actingAs($this->member)
        ->get("/w/{$this->workspace->slug}/hr/announcements")
        ->assertInertia(fn (Assert $page) => $page
            ->where('clock.open_entry.id', $entry->id)
            ->where('clock.open_entry.clock_in_at', $entry->clock_in_at->toIso8601String()));
});

it('leaves the header clock out for people without a live employee file', function () {
    $this->employee->update(['status' => 'terminated']);

    $this->actingAs($this->member)
        ->get("/w/{$this->workspace->slug}/hr/holidays")
        ->assertInertia(fn (Assert $page) => $page->where('clock', null));
});
