<?php

use App\Enums\EmploymentChange;
use App\Enums\EmploymentStatus;
use App\Enums\WorkspaceRole;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkspaceMember;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
});

it('makes every new workspace member an employee', function () {
    $member = joinWorkspace($this->workspace, User::factory()->create(['title' => 'Designer']));

    $employee = $member->employeeIn($this->workspace);

    expect($employee)->not->toBeNull()
        ->and($employee->status)->toBe(EmploymentStatus::Active)
        ->and($employee->job_title)->toBe('Designer')
        ->and($employee->hired_on->isToday())->toBeTrue()
        ->and($employee->employmentHistories()->first()->change)->toBe(EmploymentChange::Hired);
});

it('does not make bots employees', function () {
    joinWorkspace($this->workspace, User::factory()->create(['is_bot' => true]));

    expect(Employee::query()->count())->toBe(1); // just the owner
});

it('keeps the file and marks it terminated when a member is removed', function () {
    $user = joinWorkspace($this->workspace);
    $employee = $user->employeeIn($this->workspace);

    WorkspaceMember::query()->where('user_id', $user->id)->firstOrFail()->delete();

    $employee->refresh();

    expect($employee->status)->toBe(EmploymentStatus::Terminated)
        ->and($employee->workspace_member_id)->toBeNull()
        ->and($employee->terminated_on->isToday())->toBeTrue()
        ->and($employee->employmentHistories()->latest('id')->first()->change)->toBe(EmploymentChange::Terminated);
});

it('rehires the same file when somebody rejoins', function () {
    $user = joinWorkspace($this->workspace);
    $original = $user->employeeIn($this->workspace);

    WorkspaceMember::query()->where('user_id', $user->id)->firstOrFail()->delete();
    joinWorkspace($this->workspace, $user->fresh(), WorkspaceRole::Member);

    $employee = $user->fresh()->employeeIn($this->workspace);

    expect($employee->id)->toBe($original->id)
        ->and($employee->status)->toBe(EmploymentStatus::Active)
        ->and($employee->terminated_on)->toBeNull()
        ->and($employee->employmentHistories()->latest('id')->first()->change)->toBe(EmploymentChange::Rehired);
});
