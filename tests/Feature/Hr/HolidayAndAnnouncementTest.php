<?php

use App\Enums\WorkspaceRole;
use App\Models\Announcement;
use App\Models\Holiday;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->base = "/w/{$this->workspace->slug}/hr";
});

it('lets admins add holidays and everyone read them', function () {
    $this->actingAs($this->owner)
        ->post("{$this->base}/holidays", ['name' => 'Christmas', 'date' => '2026-12-25', 'is_recurring' => true])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->member)
        ->get("{$this->base}/holidays")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/holidays')
            ->where('holidays.0.name', 'Christmas')
            ->where('holidays.0.next_on', '2026-12-25')
            ->where('can_manage', false));
});

it('refuses members adding holidays', function () {
    $this->actingAs($this->member)
        ->post("{$this->base}/holidays", ['name' => 'My birthday', 'date' => '2026-11-01'])
        ->assertForbidden();
});

it('projects a recurring holiday into next year once it has passed', function () {
    $holiday = new Holiday(['name' => 'New year', 'date' => '2019-01-01', 'is_recurring' => true]);

    expect($holiday->nextOccurrence()->toDateString())->toBe('2027-01-01');
});

it('lets admins post announcements that pin to the top', function () {
    $this->actingAs($this->owner)
        ->post("{$this->base}/announcements", ['title' => 'Old news', 'body' => 'Hello'])
        ->assertSessionHasNoErrors();

    $this->travel(1)->minute();

    $this->actingAs($this->owner)
        ->post("{$this->base}/announcements", ['title' => 'Office move', 'body' => 'We move Monday.', 'is_pinned' => true]);

    $this->travel(1)->minute();

    $this->actingAs($this->owner)
        ->post("{$this->base}/announcements", ['title' => 'Newest', 'body' => 'Latest']);

    $this->actingAs($this->member)
        ->get("{$this->base}/announcements")
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/announcements')
            ->where('announcements.data.0.title', 'Office move')
            ->where('announcements.data.1.title', 'Newest'));
});

it('refuses members posting or deleting announcements', function () {
    $announcement = Announcement::create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->owner->id,
        'title' => 'Hi',
        'body' => 'There',
    ]);

    $this->actingAs($this->member)
        ->post("{$this->base}/announcements", ['title' => 'x', 'body' => 'y'])
        ->assertForbidden();

    $this->actingAs($this->member)
        ->delete("{$this->base}/announcements/{$announcement->id}")
        ->assertForbidden();
});

it('shows the HR home with announcements, holidays, and admin stats', function () {
    $this->workspace->holidays()->create(['name' => 'Christmas', 'date' => '2026-12-25']);

    $this->actingAs($this->owner)
        ->get($this->base)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/index')
            ->where('stats.headcount', 2)
            ->where('upcoming_holidays.0.name', 'Christmas')
            ->has('balances', 4));

    $this->actingAs($this->member)
        ->get($this->base)
        ->assertInertia(fn (Assert $page) => $page->where('stats', null));
});

it('keeps non-members out of HR entirely', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get($this->base)->assertNotFound();
});
