<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Workspace;

/**
 * What each leave type allows this year, and how much of it is spoken for.
 */
final class LeaveBalances
{
    /**
     * @return array<int, array{leave_type_id: int, name: string, color: string, allowance: float|null, used: float, remaining: float|null}>
     */
    public static function for(Employee $employee, Workspace $workspace, ?int $year = null): array
    {
        $used = $employee->leaveDaysUsed($year ?? today()->year);

        return $workspace->leaveTypes()
            ->available()
            ->orderBy('name')
            ->get()
            ->map(function (LeaveType $type) use ($used): array {
                $allowance = $type->allowance();
                $taken = $used[$type->id] ?? 0.0;

                return [
                    'leave_type_id' => $type->id,
                    'name' => $type->name,
                    'color' => $type->color,
                    'allowance' => $allowance,
                    'used' => $taken,
                    'remaining' => $allowance === null ? null : max(0, $allowance - $taken),
                ];
            })
            ->all();
    }
}
