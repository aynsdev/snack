<?php

namespace App\Actions\Hr;

use App\Enums\EmploymentChange;
use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkspaceMember;

/**
 * Keeps a workspace member's employee record in step with their membership.
 *
 * Every human member is an employee: joining creates the record (or re-links
 * and rehires the one left behind by an earlier membership), and leaving marks
 * it terminated rather than deleting it, so HR history survives the person.
 */
class SyncEmployeeForMember
{
    public function joined(WorkspaceMember $member): ?Employee
    {
        $user = User::query()->find($member->user_id);

        // A bot holds a membership row so it can post, but it is not staff.
        if ($user === null || $user->isBot()) {
            return null;
        }

        $employee = Employee::query()
            ->where('workspace_id', $member->workspace_id)
            ->where('user_id', $member->user_id)
            ->first();

        $today = ($member->joined_at ?? now())->toDateString();

        if ($employee === null) {
            $employee = Employee::create([
                'workspace_id' => $member->workspace_id,
                'user_id' => $member->user_id,
                'workspace_member_id' => $member->id,
                'status' => EmploymentStatus::Active,
                'hired_on' => $today,
                // Seeded from the profile the person already filled in; HR
                // owns the field from here on.
                'job_title' => $user->title,
                'department' => $user->department,
            ]);

            $employee->employmentHistories()->create([
                'change' => EmploymentChange::Hired,
                'effective_on' => $today,
                'job_title' => $employee->job_title,
                'department' => $employee->department,
                'employment_type' => $employee->employment_type,
            ]);

            return $employee;
        }

        $wasTerminated = $employee->status === EmploymentStatus::Terminated;

        $employee->update([
            'workspace_member_id' => $member->id,
            'status' => $wasTerminated ? EmploymentStatus::Active : $employee->status,
            'terminated_on' => $wasTerminated ? null : $employee->terminated_on,
        ]);

        if ($wasTerminated) {
            $employee->employmentHistories()->create([
                'change' => EmploymentChange::Rehired,
                'effective_on' => $today,
                'job_title' => $employee->job_title,
                'department' => $employee->department,
                'employment_type' => $employee->employment_type,
            ]);
        }

        return $employee;
    }

    public function left(WorkspaceMember $member): void
    {
        $employee = Employee::query()
            ->where('workspace_id', $member->workspace_id)
            ->where('user_id', $member->user_id)
            ->first();

        if ($employee === null || $employee->status === EmploymentStatus::Terminated) {
            return;
        }

        $employee->update([
            'workspace_member_id' => null,
            'status' => EmploymentStatus::Terminated,
            'terminated_on' => today()->toDateString(),
        ]);

        $employee->employmentHistories()->create([
            'change' => EmploymentChange::Terminated,
            'effective_on' => today()->toDateString(),
            'job_title' => $employee->job_title,
            'department' => $employee->department,
            'employment_type' => $employee->employment_type,
            'notes' => 'Removed from the workspace.',
        ]);
    }
}
