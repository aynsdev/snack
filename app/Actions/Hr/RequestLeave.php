<?php

namespace App\Actions\Hr;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Hr\LeaveCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Files a request for time off, counted in working days.
 *
 * Refuses a request that covers no working days, one that overlaps leave the
 * person has already booked, and one that would take them past the year's
 * allowance for that type.
 */
class RequestLeave
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly NotifyLeave $notify,
    ) {}

    public function handle(
        Employee $employee,
        LeaveType $leaveType,
        Carbon $startsOn,
        Carbon $endsOn,
        ?string $reason = null,
    ): LeaveRequest {
        $days = $this->calendar->workingDaysBetween($employee->workspace, $startsOn, $endsOn);

        if ($days <= 0) {
            throw ValidationException::withMessages([
                'starts_on' => 'Those dates fall entirely on weekends or holidays.',
            ]);
        }

        $overlaps = $employee->leaveRequests()
            ->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])
            ->overlapping($startsOn, $endsOn)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'starts_on' => 'You already have leave booked on some of those days.',
            ]);
        }

        $allowance = $leaveType->allowance();

        if ($allowance !== null) {
            $used = $employee->leaveDaysUsed($startsOn->year)[$leaveType->id] ?? 0.0;

            if ($used + $days > $allowance) {
                $remaining = max(0, $allowance - $used);

                throw ValidationException::withMessages([
                    'leave_type_id' => "That is {$days} days of {$leaveType->name}, and you have {$remaining} left this year.",
                ]);
            }
        }

        $leaveRequest = $employee->leaveRequests()->create([
            'workspace_id' => $employee->workspace_id,
            'leave_type_id' => $leaveType->id,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'days' => $days,
            'reason' => $reason,
            'status' => LeaveStatus::Pending,
        ]);

        $this->notify->requested($leaveRequest);

        return $leaveRequest;
    }
}
