<?php

use App\Enums\AuditAction;
use App\Enums\LeaveStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);
    $this->vacation = LeaveType::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Vacation',
        'days_per_year' => 5,
    ]);
    $this->base = "/w/{$this->workspace->slug}/hr/leave";
});

it('seeds default leave types and shows the leave page', function () {
    $this->actingAs($this->member)
        ->get($this->base)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/leave')
            ->where('can_request', true)
            ->where('can_manage', false));
});

it('counts working days, skipping weekends and holidays', function () {
    // Mon 5 Oct – Fri 9 Oct, with Wednesday a holiday.
    $this->workspace->holidays()->create(['name' => 'Founders day', 'date' => '2026-10-07']);

    $this->actingAs($this->member)
        ->post($this->base, [
            'leave_type_id' => $this->vacation->id,
            'starts_on' => '2026-10-03', // Saturday
            'ends_on' => '2026-10-09',
        ])
        ->assertSessionHasNoErrors();

    $leave = $this->employee->leaveRequests()->firstOrFail();

    expect((float) $leave->days)->toBe(4.0)
        ->and($leave->status)->toBe(LeaveStatus::Pending);
});

it('honours recurring holidays in later years', function () {
    $this->workspace->holidays()->create(['name' => 'New year', 'date' => '2020-01-01', 'is_recurring' => true]);

    $this->actingAs($this->member)
        ->post($this->base, [
            'leave_type_id' => $this->vacation->id,
            'starts_on' => '2027-01-01', // Friday, and a holiday
            'ends_on' => '2027-01-01',
        ])
        ->assertSessionHasErrors('starts_on');
});

it('refuses leave beyond the yearly allowance', function () {
    $this->actingAs($this->member)
        ->post($this->base, [
            'leave_type_id' => $this->vacation->id,
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-16', // ten working days
        ])
        ->assertSessionHasErrors('leave_type_id');
});

it('refuses overlapping requests', function () {
    $payload = ['leave_type_id' => $this->vacation->id, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05'];

    $this->actingAs($this->member)->post($this->base, $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->member)->post($this->base, $payload)->assertSessionHasErrors('starts_on');
});

it('refuses a leave type from another workspace', function () {
    [$other] = makeWorkspaceWithOwner();
    $foreign = LeaveType::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($this->member)
        ->post($this->base, ['leave_type_id' => $foreign->id, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05'])
        ->assertSessionHasErrors('leave_type_id');
});

it('lets an admin approve a request and audits it', function () {
    $leave = $this->employee->leaveRequests()->create([
        'workspace_id' => $this->workspace->id,
        'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-05',
        'ends_on' => '2026-10-05',
        'days' => 1,
    ]);

    $this->actingAs($this->admin)
        ->post("{$this->base}/{$leave->id}/approve", ['note' => 'Enjoy'])
        ->assertRedirect();

    expect($leave->fresh())
        ->status->toBe(LeaveStatus::Approved)
        ->reviewer_id->toBe($this->admin->id)
        ->review_note->toBe('Enjoy');

    expect(AuditLog::query()->where('action', AuditAction::LeaveApproved)->exists())->toBeTrue();

    // Already decided: a second click cannot flip it.
    $this->actingAs($this->admin)
        ->post("{$this->base}/{$leave->id}/reject")
        ->assertForbidden();
});

it('shows the review queue with each request\'s type and person', function () {
    $this->employee->leaveRequests()->create([
        'workspace_id' => $this->workspace->id,
        'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-05',
        'ends_on' => '2026-10-05',
        'days' => 1,
    ]);

    $this->actingAs($this->admin)
        ->get($this->base)
        ->assertInertia(fn (Assert $page) => $page
            ->where('queue.0.leave_type.name', 'Vacation')
            ->where('queue.0.employee.name', $this->member->name)
            ->where('queue.0.can.review', true));
});

it('does not let members review leave', function () {
    $leave = $this->employee->leaveRequests()->create([
        'workspace_id' => $this->workspace->id,
        'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-05',
        'ends_on' => '2026-10-05',
        'days' => 1,
    ]);

    $this->actingAs($this->member)
        ->post("{$this->base}/{$leave->id}/approve")
        ->assertForbidden();
});

it('does not let an admin approve their own leave', function () {
    $leave = $this->admin->employeeIn($this->workspace)->leaveRequests()->create([
        'workspace_id' => $this->workspace->id,
        'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-05',
        'ends_on' => '2026-10-05',
        'days' => 1,
    ]);

    $this->actingAs($this->admin)
        ->post("{$this->base}/{$leave->id}/approve")
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->post("{$this->base}/{$leave->id}/approve")
        ->assertRedirect();
});

it('lets the requester withdraw a pending request only', function () {
    $leave = $this->employee->leaveRequests()->create([
        'workspace_id' => $this->workspace->id,
        'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-05',
        'ends_on' => '2026-10-05',
        'days' => 1,
    ]);

    $this->actingAs($this->admin)
        ->post("{$this->base}/{$leave->id}/cancel")
        ->assertNotFound();

    $this->actingAs($this->member)
        ->post("{$this->base}/{$leave->id}/cancel")
        ->assertRedirect();

    expect($leave->fresh()->status)->toBe(LeaveStatus::Cancelled);
});

it('archives rather than deletes a leave type that has been used', function () {
    $this->employee->leaveRequests()->create([
        'workspace_id' => $this->workspace->id,
        'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-05',
        'ends_on' => '2026-10-05',
        'days' => 1,
    ]);

    $this->actingAs($this->owner)
        ->delete("/w/{$this->workspace->slug}/hr/leave-types/{$this->vacation->id}")
        ->assertRedirect();

    expect($this->vacation->fresh()->archived_at)->not->toBeNull();
});
