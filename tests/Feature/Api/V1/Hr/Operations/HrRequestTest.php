<?php

use App\Enums\RequestStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkType;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // A Tuesday.
    Carbon::setTestNow('2026-09-29 08:00:00');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);

    $this->dayShift = Shift::factory()->default()->create(['workspace_id' => $this->workspace->id]);
    $this->nightShift = Shift::factory()->overnight()->create(['workspace_id' => $this->workspace->id, 'days' => [1, 2, 3, 4, 5, 6, 7]]);

    $this->base = "/api/v1/w/{$this->workspace->slug}/hr/requests";
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };
});

it('shows your own requests and schedule, and HR the queue', function () {
    $this->employee->shiftRequests()->create([
        'workspace_id' => $this->workspace->id, 'shift_id' => $this->nightShift->id,
        'starts_on' => '2026-10-01', 'status' => RequestStatus::Pending,
    ]);
    $this->travel(1)->minute();
    $this->employee->attendanceRequests()->create([
        'workspace_id' => $this->workspace->id,
        'clock_in_at' => '2026-09-28 09:00:00', 'clock_out_at' => '2026-09-28 18:00:00',
        'reason' => 'Please', 'status' => RequestStatus::Pending,
    ]);

    ($this->as)($this->member)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(2, 'data.my_requests')
        ->assertJsonPath('data.queue', [])
        ->assertJsonPath('data.schedule.today_shift.name', 'Day shift')
        ->assertJsonPath('data.schedule.pending', 2)
        ->assertJsonPath('data.can_manage', false);

    ($this->as)($this->admin)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(2, 'data.queue')
        ->assertJsonPath('data.queue.0.type', 'attendance')
        ->assertJsonPath('data.queue.0.can.review', true)
        ->assertJsonPath('data.queue.1.type', 'shift');

    ($this->as)(User::factory()->create())->getJson($this->base)->assertNotFound();
});

describe('shift requests', function () {
    it('asks for a shift and HR approves it', function () {
        $id = ($this->as)($this->member)
            ->postJson("{$this->base}/shift", ['shift_id' => $this->nightShift->id, 'starts_on' => '2026-10-01', 'reason' => 'School'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'shift')
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->json('data.id');

        ($this->as)($this->member)->postJson("{$this->base}/shift/{$id}/approve")->assertForbidden();

        ($this->as)($this->admin)
            ->postJson("{$this->base}/shift/{$id}/approve", ['note' => 'OK'])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Approved->value)
            ->assertJsonPath('data.review_note', 'OK');

        expect($this->employee->fresh()->shift_id)->toBe($this->nightShift->id);
    });

    it('rejects, and lets only the asker withdraw', function () {
        $create = fn () => $this->employee->shiftRequests()->create([
            'workspace_id' => $this->workspace->id, 'shift_id' => $this->nightShift->id,
            'starts_on' => '2026-10-01', 'status' => RequestStatus::Pending,
        ]);
        $first = $create();
        $second = $create();

        ($this->as)($this->admin)->postJson("{$this->base}/shift/{$first->id}/reject")->assertOk()->assertJsonPath('data.status', RequestStatus::Rejected->value);

        ($this->as)($this->admin)->postJson("{$this->base}/shift/{$second->id}/cancel")->assertNotFound();
        ($this->as)($this->member)->postJson("{$this->base}/shift/{$second->id}/cancel")->assertOk()->assertJsonPath('data.status', RequestStatus::Cancelled->value);
    });

    it('refuses a shift from another workspace or a start in the past', function () {
        [$other] = makeWorkspaceWithOwner();
        $foreign = Shift::factory()->create(['workspace_id' => $other->id]);

        ($this->as)($this->member)
            ->postJson("{$this->base}/shift", ['shift_id' => $foreign->id, 'starts_on' => '2026-09-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shift_id', 'starts_on']);
    });
});

describe('work type requests', function () {
    it('asks for a work type, and HR approves, rejects, or the asker withdraws', function () {
        $id = ($this->as)($this->member)
            ->postJson("{$this->base}/work-type", ['work_type' => 'remote', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'work_type')
            ->json('data.id');

        ($this->as)($this->admin)->postJson("{$this->base}/work-type/{$id}/approve")->assertOk()->assertJsonPath('data.status', RequestStatus::Approved->value);

        $create = fn () => $this->employee->workTypeRequests()->create([
            'workspace_id' => $this->workspace->id, 'work_type' => WorkType::Hybrid,
            'starts_on' => '2026-11-01', 'status' => RequestStatus::Pending,
        ]);
        $rejected = $create();
        $withdrawn = $create();

        ($this->as)($this->admin)->postJson("{$this->base}/work-type/{$rejected->id}/reject", ['note' => 'No'])->assertOk()->assertJsonPath('data.status', RequestStatus::Rejected->value);
        ($this->as)($this->member)->postJson("{$this->base}/work-type/{$withdrawn->id}/cancel")->assertOk()->assertJsonPath('data.status', RequestStatus::Cancelled->value);
    });
});

describe('attendance requests', function () {
    it('adds a missing shift on approval', function () {
        $id = ($this->as)($this->member)
            ->postJson("{$this->base}/attendance", [
                'clock_in_at' => '2026-09-28T01:30:00Z',
                'clock_out_at' => '2026-09-28T10:00:00Z',
                'reason' => 'Forgot to clock in',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'attendance')
            ->json('data.id');

        ($this->as)($this->admin)->postJson("{$this->base}/attendance/{$id}/approve")->assertOk()->assertJsonPath('data.status', RequestStatus::Approved->value);

        expect($this->employee->timeEntries()->count())->toBe(1);
    });

    it('changes nothing on rejection, and can be withdrawn', function () {
        $create = fn () => $this->employee->attendanceRequests()->create([
            'workspace_id' => $this->workspace->id,
            'clock_in_at' => '2026-09-28 09:00:00', 'clock_out_at' => '2026-09-28 18:00:00',
            'reason' => 'Please', 'status' => RequestStatus::Pending,
        ]);
        $rejected = $create();
        $withdrawn = $create();

        ($this->as)($this->member)->postJson("{$this->base}/attendance/{$rejected->id}/reject")->assertForbidden();
        ($this->as)($this->admin)->postJson("{$this->base}/attendance/{$rejected->id}/reject", ['note' => 'No proof'])->assertOk()->assertJsonPath('data.status', RequestStatus::Rejected->value);
        ($this->as)($this->member)->postJson("{$this->base}/attendance/{$withdrawn->id}/cancel")->assertOk()->assertJsonPath('data.status', RequestStatus::Cancelled->value);

        expect($this->employee->timeEntries()->count())->toBe(0);
    });
});
