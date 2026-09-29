<?php

use App\Enums\WorkspaceRole;
use App\Models\Conversation;
use App\Models\LeaveType;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->colleague = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->vacation = LeaveType::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Vacation']);
    $this->base = "/w/{$this->workspace->slug}/hr/leave";
});

/**
 * The direct messages the HR bot has sent to one person.
 *
 * @return Collection<int, Message>
 */
function hrMessagesTo(User $recipient)
{
    $bot = User::query()->where('email', 'like', 'hr-bot+%')->first();

    if ($bot === null) {
        return collect();
    }

    return Message::query()
        ->where('sender_id', $bot->id)
        ->whereIn('conversation_id', Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('user_id', $recipient->id))
            ->select('id'))
        ->get();
}

it('messages every admin when leave is requested, but not the requester or colleagues', function () {
    $this->actingAs($this->member)
        ->post($this->base, [
            'leave_type_id' => $this->vacation->id,
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-07',
            'reason' => 'Family trip',
        ])
        ->assertSessionHasNoErrors();

    foreach ([$this->owner, $this->admin] as $reviewer) {
        $messages = hrMessagesTo($reviewer);

        expect($messages)->toHaveCount(1)
            ->and($messages->first()->body)->toContain($this->member->name)
            ->and($messages->first()->body)->toContain('Vacation, 3 days, Oct 5 – Oct 7, 2026')
            ->and($messages->first()->body)->toContain('Family trip')
            ->and($messages->first()->body)->toContain('/hr/leave');
    }

    expect(hrMessagesTo($this->member))->toHaveCount(0)
        ->and(hrMessagesTo($this->colleague))->toHaveCount(0);
});

it('does not message an admin about their own request', function () {
    $this->actingAs($this->admin)
        ->post($this->base, [
            'leave_type_id' => $this->vacation->id,
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-05',
        ])
        ->assertSessionHasNoErrors();

    expect(hrMessagesTo($this->admin))->toHaveCount(0)
        ->and(hrMessagesTo($this->owner))->toHaveCount(1);
});

it('tells the employee when their leave is approved or rejected', function () {
    $employee = $this->member->employeeIn($this->workspace);

    $approved = $employee->leaveRequests()->create([
        'workspace_id' => $this->workspace->id,
        'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-05',
        'ends_on' => '2026-10-05',
        'days' => 1,
    ]);

    $this->actingAs($this->admin)
        ->post("{$this->base}/{$approved->id}/approve", ['note' => 'Enjoy'])
        ->assertRedirect();

    $rejected = $employee->leaveRequests()->create([
        'workspace_id' => $this->workspace->id,
        'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-12',
        'ends_on' => '2026-10-12',
        'days' => 1,
    ]);

    $this->actingAs($this->admin)
        ->post("{$this->base}/{$rejected->id}/reject", ['note' => 'Release week'])
        ->assertRedirect();

    $bodies = hrMessagesTo($this->member)->pluck('body');

    expect($bodies)->toHaveCount(2)
        ->and($bodies[0])->toContain('**approved ✅**')
        ->and($bodies[0])->toContain("by {$this->admin->name}")
        ->and($bodies[0])->toContain('Enjoy')
        ->and($bodies[1])->toContain('**rejected**')
        ->and($bodies[1])->toContain('Release week');
});

it('keeps the HR bot out of the employee list and seat count', function () {
    $this->actingAs($this->member)->post($this->base, [
        'leave_type_id' => $this->vacation->id,
        'starts_on' => '2026-10-05',
        'ends_on' => '2026-10-05',
    ]);

    $bot = User::query()->where('email', 'like', 'hr-bot+%')->firstOrFail();

    expect($bot->isBot())->toBeTrue()
        ->and($bot->employeeIn($this->workspace))->toBeNull();
});
