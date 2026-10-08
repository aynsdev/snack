<?php

namespace App\Services\Hr;

use App\Enums\WorkType;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftRequest;
use App\Models\WorkTypeRequest;
use Carbon\CarbonInterface;

/**
 * Which shift, and which work type, somebody is on for a given day.
 *
 * In order: an approved temporary request covering the day, then what is on
 * their file, then — for shifts — the workspace's default. Temporary requests
 * are read here rather than written to the file, which is what lets them lapse
 * on their own when their last day passes.
 */
class ShiftResolver
{
    public function shiftFor(Employee $employee, CarbonInterface $date): ?Shift
    {
        $temporary = $employee->shiftRequests()
            ->approved()
            ->whereNotNull('ends_on')
            ->covering($date)
            ->latest('reviewed_at')
            ->with('shift')
            ->first();

        if ($temporary instanceof ShiftRequest) {
            return $temporary->shift;
        }

        return $employee->shift ?? $employee->workspace->shifts()->default()->first();
    }

    public function workTypeFor(Employee $employee, CarbonInterface $date): WorkType
    {
        $temporary = $employee->workTypeRequests()
            ->approved()
            ->whereNotNull('ends_on')
            ->covering($date)
            ->latest('reviewed_at')
            ->first();

        return $temporary instanceof WorkTypeRequest ? $temporary->work_type : $employee->work_type;
    }
}
