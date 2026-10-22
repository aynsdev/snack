<?php

use App\Enums\LeaveStatus;
use App\Enums\WorkspaceRole;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Guards cache the resolved user, so a token for somebody else needs
    // them forgotten first.
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };

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
    $this->base = "/api/v1/w/{$this->workspace->slug}/hr";
    $this->pending = fn (): LeaveRequest => LeaveRequest::factory()->create([
        'employee_id' => $this->employee->id,
        'leave_type_id' => $this->vacation->id,
    ]);
});

it('shows the leave page', function () {
    ($this->as)($this->member)
        ->getJson("{$this->base}/leave")
        ->assertOk()
        ->assertJsonPath('data.can_request', true)
        ->assertJsonPath('data.can_manage', false)
        ->assertJsonPath('data.queue', [])
        ->assertJsonStructure(['data' => ['leave_types', 'balances', 'my_requests', 'statuses', 'holidays']]);
});

it('shows HR the pending queue', function () {
    ($this->pending)();

    ($this->as)($this->admin)
        ->getJson("{$this->base}/leave")
        ->assertOk()
        ->assertJsonCount(1, 'data.queue')
        ->assertJsonPath('data.filters.status', 'pending');
});

it('requests leave counting working days', function () {
    ($this->as)($this->member)
        ->postJson("{$this->base}/leave", [
            'leave_type_id' => $this->vacation->id,
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-07',
            'reason' => 'Trip',
        ])
        ->assertCreated()
        ->assertJsonPath('data.days', 3)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.can.cancel', true);
});

it('refuses leave beyond the allowance', function () {
    ($this->as)($this->member)
        ->postJson("{$this->base}/leave", [
            'leave_type_id' => $this->vacation->id,
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-16',
        ])
        ->assertUnprocessable();
});

it('cancels your own pending request', function () {
    $leave = ($this->pending)();

    ($this->as)($this->member)
        ->postJson("{$this->base}/leave/{$leave->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('approves and rejects leave', function () {
    $first = ($this->pending)();
    $second = ($this->pending)();

    ($this->as)($this->admin)
        ->postJson("{$this->base}/leave/{$first->id}/approve", ['note' => 'Enjoy'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.review_note', 'Enjoy');

    ($this->as)($this->admin)
        ->postJson("{$this->base}/leave/{$second->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});

it('keeps reviewing from ordinary members', function () {
    $leave = ($this->pending)();

    ($this->as)($this->member)
        ->postJson("{$this->base}/leave/{$leave->id}/approve")
        ->assertForbidden();

    expect($leave->refresh()->status)->toBe(LeaveStatus::Pending);
});

it('lists leave types, archived ones for HR only', function () {
    $this->vacation->update(['archived_at' => now()]);

    ($this->as)($this->member)
        ->getJson("{$this->base}/leave-types")
        ->assertOk()
        ->assertJsonMissing(['name' => 'Vacation']);

    ($this->as)($this->admin)
        ->getJson("{$this->base}/leave-types")
        ->assertOk()
        ->assertJsonFragment(['name' => 'Vacation', 'is_archived' => true]);
});

it('manages leave types', function () {
    $id = ($this->as)($this->admin)
        ->postJson("{$this->base}/leave-types", ['name' => 'Study', 'color' => 'violet', 'days_per_year' => 3])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Study')
        ->json('data.id');

    ($this->as)($this->admin)
        ->patchJson("{$this->base}/leave-types/{$id}", ['name' => 'Study leave', 'color' => 'violet', 'is_paid' => false])
        ->assertOk()
        ->assertJsonPath('data.is_paid', false);

    ($this->as)($this->admin)
        ->deleteJson("{$this->base}/leave-types/{$id}")
        ->assertNoContent();

    // A used type is archived rather than deleted.
    ($this->pending)();

    ($this->as)($this->admin)
        ->deleteJson("{$this->base}/leave-types/{$this->vacation->id}")
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);
});

it('keeps leave type management from ordinary members', function () {
    ($this->as)($this->member)
        ->postJson("{$this->base}/leave-types", ['name' => 'Study', 'color' => 'violet'])
        ->assertForbidden();
});
