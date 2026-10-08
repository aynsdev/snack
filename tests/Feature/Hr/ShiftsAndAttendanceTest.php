<?php

use App\Actions\Hr\ClockTime;
use App\Enums\AuditAction;
use App\Enums\RequestStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkType;
use App\Models\AttendanceRequest;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\Shift;
use App\Models\ShiftRequest;
use App\Models\User;
use App\Models\WorkTypeRequest;
use App\Services\Hr\ShiftResolver;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // A Tuesday.
    Carbon::setTestNow('2026-09-29 08:00:00');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);

    $this->dayShift = Shift::factory()->default()->create(['workspace_id' => $this->workspace->id]);
    $this->nightShift = Shift::factory()->overnight()->create(['workspace_id' => $this->workspace->id, 'days' => [1, 2, 3, 4, 5, 6, 7]]);

    $this->base = "/w/{$this->workspace->slug}/hr";
});

function botMessagesTo(User $recipient): int
{
    return Message::query()
        ->whereHas('sender', fn ($query) => $query->where('email', 'like', 'hr-bot+%'))
        ->whereHas('conversation.participants', fn ($query) => $query->where('user_id', $recipient->id))
        ->count();
}

describe('shifts', function () {
    it('lets HR create shifts and keeps a single default', function () {
        $this->actingAs($this->admin)
            ->post("{$this->base}/shifts", [
                'name' => 'Early', 'starts_at' => '06:00', 'ends_at' => '15:00',
                'grace_minutes' => 5, 'days' => [5, 1, 3], 'is_default' => true,
            ])
            ->assertSessionHasNoErrors();

        $early = $this->workspace->shifts()->where('name', 'Early')->sole();

        expect($early->days)->toBe([1, 3, 5])
            ->and($early->is_default)->toBeTrue()
            ->and($this->dayShift->fresh()->is_default)->toBeFalse()
            ->and(AuditLog::query()->where('action', AuditAction::ShiftCreated)->exists())->toBeTrue();
    });

    it('keeps members from managing shifts', function () {
        $this->actingAs($this->member)
            ->post("{$this->base}/shifts", ['name' => 'Mine', 'starts_at' => '10:00', 'ends_at' => '11:00', 'grace_minutes' => 0, 'days' => [1]])
            ->assertForbidden();

        $this->actingAs($this->member)->delete("{$this->base}/shifts/{$this->dayShift->id}")->assertForbidden();
    });

    it('does not reach another workspace\'s shift', function () {
        [$other] = makeWorkspaceWithOwner();
        $foreign = Shift::factory()->create(['workspace_id' => $other->id]);

        $this->actingAs($this->admin)->delete("{$this->base}/shifts/{$foreign->id}")->assertNotFound();
    });

    it('lists shifts on the HR settings page', function () {
        $this->actingAs($this->admin)
            ->get("{$this->base}/settings")
            ->assertInertia(fn (Assert $page) => $page->has('shifts', 2)->where('shifts.0.hours', '09:00 – 18:00'));
    });
});

describe('punctuality', function () {
    it('measures lateness beyond the grace period, and early leaving', function () {
        $clock = app(ClockTime::class);

        $this->travelTo(Carbon::parse('2026-09-29 09:25:00'));
        $entry = $clock->in($this->employee, []);

        expect($entry->late_minutes)->toBe(25)->and($entry->shift_id)->toBe($this->dayShift->id);

        $this->travelTo(Carbon::parse('2026-09-29 17:30:00'));
        $entry = $clock->out($this->employee, []);

        expect($entry->early_out_minutes)->toBe(30)->and($entry->overtime_minutes)->toBe(0);
    });

    it('forgives lateness within the grace period and counts overtime', function () {
        $clock = app(ClockTime::class);

        $this->travelTo(Carbon::parse('2026-09-29 09:08:00'));
        $clock->in($this->employee, []);
        $this->travelTo(Carbon::parse('2026-09-29 19:00:00'));
        $entry = $clock->out($this->employee, []);

        expect($entry->late_minutes)->toBe(0)
            ->and($entry->early_out_minutes)->toBe(0)
            ->and($entry->overtime_minutes)->toBe(60);
    });

    it('measures nothing on a day the shift is not worked', function () {
        $this->travelTo(Carbon::parse('2026-10-03 11:00:00'));

        $entry = app(ClockTime::class)->in($this->employee, []);

        expect($entry->late_minutes)->toBeNull()->and($entry->shift_id)->toBe($this->dayShift->id);
    });

    it('handles a shift that runs past midnight', function () {
        $this->employee->update(['shift_id' => $this->nightShift->id]);
        $clock = app(ClockTime::class);

        $this->travelTo(Carbon::parse('2026-09-29 22:15:00'));
        $clock->in($this->employee, []);
        $this->travelTo(Carbon::parse('2026-09-30 06:30:00'));
        $entry = $clock->out($this->employee, []);

        expect($entry->late_minutes)->toBe(15)
            ->and($entry->early_out_minutes)->toBe(0)
            ->and($entry->overtime_minutes)->toBe(30);
    });

    it('re-measures a shift HR corrects, and filters the timesheet by lateness', function () {
        $entry = $this->employee->timeEntries()->create([
            'workspace_id' => $this->workspace->id,
            'clock_in_at' => '2026-09-28 09:00:00',
            'clock_out_at' => '2026-09-28 18:00:00',
        ]);

        $this->actingAs($this->admin)
            ->patch("{$this->base}/time/{$entry->id}", [
                'clock_in_at' => '2026-09-28T02:00:00Z', // 10:00 in Manila
                'clock_out_at' => '2026-09-28T10:00:00Z',
            ])
            ->assertSessionHasNoErrors();

        expect($entry->fresh()->late_minutes)->toBe(60);

        $this->actingAs($this->admin)
            ->get("{$this->base}/time?scope=team&from=2026-09-28&to=2026-09-29&flag=late")
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 1)
                ->where('entries.0.late_minutes', 60)
                ->where('filters.flag', 'late'));
    });
});

describe('shift requests', function () {
    it('applies a permanent shift change on approval and tells both sides', function () {
        $this->actingAs($this->member)
            ->post("{$this->base}/requests/shift", ['shift_id' => $this->nightShift->id, 'starts_on' => '2026-10-01', 'reason' => 'School'])
            ->assertSessionHasNoErrors();

        $request = ShiftRequest::query()->sole();

        expect(botMessagesTo($this->admin))->toBe(1);

        $this->actingAs($this->admin)
            ->post("{$this->base}/requests/shift/{$request->id}/approve", ['note' => 'OK'])
            ->assertSessionHasNoErrors();

        expect($request->fresh()->status)->toBe(RequestStatus::Approved)
            ->and($this->employee->fresh()->shift_id)->toBe($this->nightShift->id)
            ->and(botMessagesTo($this->member))->toBe(1)
            ->and(AuditLog::query()->where('action', AuditAction::ShiftRequestApproved)->exists())->toBeTrue();

        $this->actingAs($this->admin)->post("{$this->base}/requests/shift/{$request->id}/reject")->assertForbidden();
    });

    it('covers only its own days when temporary', function () {
        $request = $this->employee->shiftRequests()->create([
            'workspace_id' => $this->workspace->id, 'shift_id' => $this->nightShift->id,
            'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02', 'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($this->owner)->post("{$this->base}/requests/shift/{$request->id}/approve");

        $resolver = app(ShiftResolver::class);
        $employee = $this->employee->fresh();

        expect($employee->shift_id)->toBeNull()
            ->and($resolver->shiftFor($employee, Carbon::parse('2026-10-01'))->id)->toBe($this->nightShift->id)
            ->and($resolver->shiftFor($employee, Carbon::parse('2026-10-03'))->id)->toBe($this->dayShift->id);
    });

    it('lets only the asker withdraw, and only while pending', function () {
        $request = $this->employee->shiftRequests()->create([
            'workspace_id' => $this->workspace->id, 'shift_id' => $this->nightShift->id,
            'starts_on' => '2026-10-01', 'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($this->admin)->post("{$this->base}/requests/shift/{$request->id}/cancel")->assertNotFound();
        $this->actingAs($this->member)->post("{$this->base}/requests/shift/{$request->id}/cancel")->assertSessionHasNoErrors();

        expect($request->fresh()->status)->toBe(RequestStatus::Cancelled);

        $this->actingAs($this->member)->post("{$this->base}/requests/shift/{$request->id}/cancel")->assertForbidden();
    });

    it('keeps members from reviewing and admins from reviewing their own', function () {
        $memberRequest = $this->employee->shiftRequests()->create([
            'workspace_id' => $this->workspace->id, 'shift_id' => $this->nightShift->id,
            'starts_on' => '2026-10-01', 'status' => RequestStatus::Pending,
        ]);
        $adminRequest = $this->admin->employeeIn($this->workspace)->shiftRequests()->create([
            'workspace_id' => $this->workspace->id, 'shift_id' => $this->nightShift->id,
            'starts_on' => '2026-10-01', 'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($this->member)->post("{$this->base}/requests/shift/{$memberRequest->id}/approve")->assertForbidden();
        $this->actingAs($this->admin)->post("{$this->base}/requests/shift/{$adminRequest->id}/approve")->assertForbidden();
        $this->actingAs($this->owner)->post("{$this->base}/requests/shift/{$adminRequest->id}/approve")->assertSessionHasNoErrors();
    });

    it('refuses a shift from another workspace or a start in the past', function () {
        [$other] = makeWorkspaceWithOwner();
        $foreign = Shift::factory()->create(['workspace_id' => $other->id]);

        $this->actingAs($this->member)
            ->post("{$this->base}/requests/shift", ['shift_id' => $foreign->id, 'starts_on' => '2026-09-01'])
            ->assertSessionHasErrors(['shift_id', 'starts_on']);
    });
});

describe('work type requests', function () {
    it('changes the work type for good, or just for its days', function () {
        $this->actingAs($this->member)
            ->post("{$this->base}/requests/work-type", ['work_type' => 'remote', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-09'])
            ->assertSessionHasNoErrors();

        $temporary = WorkTypeRequest::query()->sole();
        $this->actingAs($this->admin)->post("{$this->base}/requests/work-type/{$temporary->id}/approve");

        $resolver = app(ShiftResolver::class);
        $employee = $this->employee->fresh();

        expect($employee->work_type)->toBe(WorkType::Onsite)
            ->and($resolver->workTypeFor($employee, Carbon::parse('2026-10-06')))->toBe(WorkType::Remote);

        $permanent = $this->employee->workTypeRequests()->create([
            'workspace_id' => $this->workspace->id, 'work_type' => WorkType::Hybrid,
            'starts_on' => '2026-11-01', 'status' => RequestStatus::Pending,
        ]);
        $this->actingAs($this->admin)->post("{$this->base}/requests/work-type/{$permanent->id}/approve");

        expect($this->employee->fresh()->work_type)->toBe(WorkType::Hybrid);
    });
});

describe('attendance requests', function () {
    it('adds a missing shift on approval, measured against the shift', function () {
        $this->actingAs($this->member)
            ->post("{$this->base}/requests/attendance", [
                'clock_in_at' => '2026-09-28T01:30:00Z', // 09:30 in Manila
                'clock_out_at' => '2026-09-28T10:00:00Z',
                'reason' => 'Forgot to clock in',
            ])
            ->assertSessionHasNoErrors();

        $request = AttendanceRequest::query()->sole();

        $this->actingAs($this->admin)
            ->post("{$this->base}/requests/attendance/{$request->id}/approve")
            ->assertSessionHasNoErrors();

        $entry = $this->employee->timeEntries()->sole();

        expect($entry->clock_in_at->format('Y-m-d H:i'))->toBe('2026-09-28 09:30')
            ->and($entry->late_minutes)->toBe(30)
            ->and($request->fresh()->time_entry_id)->toBe($entry->id)
            ->and(AuditLog::query()->where('action', AuditAction::AttendanceRequestApproved)->exists())->toBeTrue();
    });

    it('corrects an existing entry, but only one of your own', function () {
        $entry = $this->employee->timeEntries()->create([
            'workspace_id' => $this->workspace->id,
            'clock_in_at' => '2026-09-28 10:00:00',
            'clock_out_at' => '2026-09-28 18:00:00',
        ]);
        $someoneElses = $this->admin->employeeIn($this->workspace)->timeEntries()->create([
            'workspace_id' => $this->workspace->id,
            'clock_in_at' => '2026-09-28 09:00:00',
            'clock_out_at' => '2026-09-28 18:00:00',
        ]);

        $this->actingAs($this->member)
            ->post("{$this->base}/requests/attendance", [
                'time_entry_id' => $someoneElses->id,
                'clock_in_at' => '2026-09-28T01:00:00Z', 'clock_out_at' => '2026-09-28T10:00:00Z', 'reason' => 'Mine now',
            ])
            ->assertSessionHasErrors('time_entry_id');

        $this->actingAs($this->member)
            ->post("{$this->base}/requests/attendance", [
                'time_entry_id' => $entry->id,
                'clock_in_at' => '2026-09-28T01:00:00Z', 'clock_out_at' => '2026-09-28T10:00:00Z', 'reason' => 'I was on time',
            ])
            ->assertSessionHasNoErrors();

        $request = AttendanceRequest::query()->sole();
        $this->actingAs($this->admin)->post("{$this->base}/requests/attendance/{$request->id}/approve");

        expect($entry->fresh()->clock_in_at->format('H:i'))->toBe('09:00')
            ->and($entry->fresh()->late_minutes)->toBe(0)
            ->and($this->employee->timeEntries()->count())->toBe(1);
    });

    it('changes nothing on rejection', function () {
        $request = $this->employee->attendanceRequests()->create([
            'workspace_id' => $this->workspace->id,
            'clock_in_at' => '2026-09-28 09:00:00', 'clock_out_at' => '2026-09-28 18:00:00',
            'reason' => 'Please', 'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($this->admin)->post("{$this->base}/requests/attendance/{$request->id}/reject", ['note' => 'No proof']);

        expect($request->fresh()->status)->toBe(RequestStatus::Rejected)
            ->and($this->employee->timeEntries()->count())->toBe(0);
    });
});

describe('requests page and dashboard', function () {
    beforeEach(function () {
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
    });

    it('shows your own requests and schedule, and HR the queue', function () {
        $this->actingAs($this->member)
            ->get("{$this->base}/requests")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('hr/requests')
                ->has('my_requests', 2)
                ->where('queue', [])
                ->where('schedule.today_shift.name', 'Day shift')
                ->where('schedule.pending', 2)
                ->where('can_manage', false));

        $this->actingAs($this->admin)
            ->get("{$this->base}/requests")
            ->assertInertia(fn (Assert $page) => $page
                ->has('queue', 2)
                ->where('queue.0.type', 'attendance')
                ->where('queue.0.can.review', true)
                ->where('queue.1.type', 'shift'));
    });

    it('counts the requests and late arrivals on the HR dashboard', function () {
        $this->employee->timeEntries()->create([
            'workspace_id' => $this->workspace->id,
            'clock_in_at' => '2026-09-28 09:40:00', 'clock_out_at' => '2026-09-28 18:00:00', 'late_minutes' => 40,
        ]);

        $this->actingAs($this->admin)
            ->get("{$this->base}/dashboard")
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.pending_approvals', 2)
                ->where('pending_requests', ['shift' => 1, 'work_type' => 0, 'attendance' => 1])
                ->where('late_arrivals.0.employee_id', $this->employee->id)
                ->where('late_arrivals.0.minutes', 40));
    });
});
