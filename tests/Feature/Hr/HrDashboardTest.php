<?php

use App\Enums\ContractStatus;
use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Enums\LeaveStatus;
use App\Enums\WorkspaceRole;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->ownerFile = $this->owner->employeeIn($this->workspace);
    $this->adminFile = $this->admin->employeeIn($this->workspace);
    $this->memberFile = $this->member->employeeIn($this->workspace);

    $this->ownerFile->update(['department' => 'Engineering', 'hired_on' => '2020-10-05', 'birth_date' => '1990-10-10']);
    $this->adminFile->update(['department' => 'Engineering', 'hired_on' => '2026-05-12']);
    $this->memberFile->update(['department' => null, 'employment_type' => EmploymentType::Contractor, 'hired_on' => '2026-09-01']);

    $this->vacation = LeaveType::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Vacation', 'days_per_year' => 15]);
    $this->url = "/w/{$this->workspace->slug}/hr/dashboard";
});

it('is for HR admins only', function () {
    $this->actingAs($this->owner)->get($this->url)->assertOk();
    $this->actingAs($this->admin)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('hr/dashboard'));
    $this->actingAs($this->member)->get($this->url)->assertForbidden();
    $this->actingAs(User::factory()->create())->get($this->url)->assertNotFound();
});

it('counts the workforce, this month\'s movement, and what is waiting on HR', function () {
    $leaver = joinWorkspace($this->workspace)->employeeIn($this->workspace);
    $leaver->update(['status' => EmploymentStatus::Terminated, 'hired_on' => '2024-01-01', 'terminated_on' => '2026-09-15']);

    $this->memberFile->leaveRequests()->create([
        'workspace_id' => $this->workspace->id, 'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-05', 'ends_on' => '2026-10-06', 'days' => 2, 'status' => LeaveStatus::Pending,
    ]);
    $this->adminFile->leaveRequests()->create([
        'workspace_id' => $this->workspace->id, 'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-09-28', 'ends_on' => '2026-09-30', 'days' => 3, 'status' => LeaveStatus::Approved,
    ]);
    $this->ownerFile->timeEntries()->create(['workspace_id' => $this->workspace->id, 'clock_in_at' => now()->subHour()]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpis.headcount', 3)
            ->where('kpis.new_hires', 1)
            ->where('kpis.leavers', 1)
            ->where('kpis.on_leave_today', 1)
            ->where('kpis.clocked_in', 1)
            ->where('kpis.pending_approvals', 1)
            ->where('attendance.clocked_in_today', 1)
            ->where('attendance.on_leave', 1)
            ->where('attendance.not_in', 1)
            ->where('attendance.week_minutes', 60)
            ->has('pending_leave', 1)
            ->where('pending_leave.0.employee.id', $this->memberFile->id)
            ->where('leave_by_type.0.label', 'Vacation')
            ->where('leave_by_type.0.count', 3)
            ->has('out_this_week', 1));
});

it('groups headcount by department with the unassigned last, and by employment type', function () {
    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->where('departments', [
                ['key' => 'Engineering', 'label' => 'Engineering', 'count' => 2],
                ['key' => '_none', 'label' => 'Unassigned', 'count' => 1],
            ])
            ->where('employment_types.0', ['key' => 'full_time', 'label' => 'Full-time', 'count' => 2])
            ->where('employment_types.2', ['key' => 'contractor', 'label' => 'Contractor', 'count' => 1]));
});

it('charts joiners and leavers over the last six months', function () {
    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->has('movement', 6)
            ->where('movement.0', ['month' => '2026-04', 'hires' => 0, 'leavers' => 0])
            ->where('movement.1', ['month' => '2026-05', 'hires' => 1, 'leavers' => 0])
            ->where('movement.5', ['month' => '2026-09', 'hires' => 1, 'leavers' => 0]));
});

it('lists birthdays, work anniversaries, and contracts ending within 30 days', function () {
    $this->ownerFile->contracts()->create([
        'title' => 'Fixed term', 'employment_type' => EmploymentType::FullTime,
        'status' => ContractStatus::Active, 'starts_on' => '2025-10-01', 'ends_on' => '2026-10-15',
    ]);
    $this->adminFile->contracts()->create([
        'title' => 'Far off', 'employment_type' => EmploymentType::FullTime,
        'status' => ContractStatus::Active, 'starts_on' => '2026-05-12', 'ends_on' => '2027-05-12',
    ]);

    $this->actingAs($this->owner)
        ->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->has('celebrations', 2)
            ->where('celebrations.0.kind', 'anniversary')
            ->where('celebrations.0.date', '2026-10-05')
            ->where('celebrations.0.years', 6)
            ->where('celebrations.1.kind', 'birthday')
            ->where('celebrations.1.date', '2026-10-10')
            ->has('expiring_contracts', 1)
            ->where('expiring_contracts.0.title', 'Fixed term'));
});
