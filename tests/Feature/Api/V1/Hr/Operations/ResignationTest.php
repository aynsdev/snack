<?php

use App\Enums\ChecklistKind;
use App\Enums\ResignationStatus;
use App\Enums\WorkspaceRole;
use App\Models\ChecklistTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);

    ChecklistTemplate::seedDefaultsFor($this->workspace);

    $this->base = "/api/v1/w/{$this->workspace->slug}/hr/resignations";
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };
    $this->pending = fn () => $this->employee->resignations()->create([
        'workspace_id' => $this->workspace->id, 'reason' => 'x', 'last_day_on' => '2026-10-30', 'status' => ResignationStatus::Pending,
    ]);
});

it('hands in notice, and starts offboarding when HR accepts', function () {
    $id = ($this->as)($this->member)
        ->postJson($this->base, ['reason' => 'Moving abroad', 'last_day_on' => '2026-10-30'])
        ->assertCreated()
        ->assertJsonPath('data.status', ResignationStatus::Pending->value)
        ->assertJsonPath('data.can.withdraw', true)
        ->json('data.id');

    ($this->as)($this->member)->postJson($this->base, ['reason' => 'Again', 'last_day_on' => '2026-10-30'])->assertForbidden();

    ($this->as)($this->admin)
        ->postJson("{$this->base}/{$id}/accept", ['note' => 'Good luck'])
        ->assertOk()
        ->assertJsonPath('data.status', ResignationStatus::Approved->value)
        ->assertJsonPath('data.review_note', 'Good luck');

    expect($this->employee->checklists()->sole()->kind)->toBe(ChecklistKind::Offboarding);
});

it('rejects a resignation', function () {
    $resignation = ($this->pending)();

    ($this->as)($this->admin)
        ->postJson("{$this->base}/{$resignation->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.status', ResignationStatus::Rejected->value);
});

it('lets only the person withdraw, and only HR decide', function () {
    $resignation = ($this->pending)();

    ($this->as)($this->member)->postJson("{$this->base}/{$resignation->id}/accept")->assertForbidden();
    ($this->as)($this->admin)->postJson("{$this->base}/{$resignation->id}/withdraw")->assertNotFound();
    ($this->as)($this->member)
        ->postJson("{$this->base}/{$resignation->id}/withdraw")
        ->assertOk()
        ->assertJsonPath('data.status', ResignationStatus::Withdrawn->value);
});

it('refuses a last day in the past', function () {
    ($this->as)($this->member)
        ->postJson($this->base, ['reason' => 'x', 'last_day_on' => '2026-09-01'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('last_day_on');
});
