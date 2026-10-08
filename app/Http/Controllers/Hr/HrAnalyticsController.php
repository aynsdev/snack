<?php

namespace App\Http\Controllers\Hr;

use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hr\LeaveRequestResource;
use App\Models\Employee;
use App\Models\Workspace;
use App\Services\Hr\HrDashboardStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR's own dashboard: the workforce at a glance and everything waiting on HR.
 *
 * Separate from the HR home ({@see HrDashboardController}), which is every
 * employee's self-service page.
 */
class HrAnalyticsController extends Controller
{
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [Employee::class, $workspace]);

        $stats = new HrDashboardStats($workspace);

        return Inertia::render('hr/dashboard', [
            'kpis' => $stats->kpis(),
            'departments' => $stats->departments(),
            'employment_types' => $stats->employmentTypes(),
            'movement' => $stats->movement(),
            'attendance' => $stats->attendance(),
            'leave_by_type' => $stats->leaveByType(),
            'out_this_week' => $stats->outThisWeek(),
            'celebrations' => $stats->celebrations(),
            'expiring_contracts' => $stats->expiringContracts(),
            'pending_requests' => $stats->pendingRequests(),
            'late_arrivals' => $stats->lateArrivals(),
            'pending_leave' => LeaveRequestResource::collection(
                $workspace->leaveRequests()
                    ->where('status', LeaveStatus::Pending)
                    ->with(['employee.user', 'leaveType', 'reviewer', 'workspace'])
                    ->orderBy('starts_on')
                    ->limit(10)
                    ->get(),
            )->resolve($request),
        ]);
    }
}
