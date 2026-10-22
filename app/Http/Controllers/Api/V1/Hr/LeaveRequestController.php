<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\RequestLeave;
use App\Enums\LeaveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreLeaveRequestRequest;
use App\Http\Resources\Hr\LeaveRequestResource;
use App\Http\Resources\Hr\LeaveTypeResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Workspace;
use App\Services\Hr\LeaveBalances;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Asking for time off, and — for HR — the queue of requests to decide.
 */
class LeaveRequestController extends Controller
{
    private const array RELATIONS = ['employee.user', 'leaveType', 'reviewer', 'workspace'];

    /**
     * Query: `status` (a LeaveStatus) filters HR's queue, pending by default.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('view', $workspace);

        LeaveType::seedDefaultsFor($workspace);

        $user = $request->user();
        $employee = $user->employeeIn($workspace);
        $canManage = $user->roleIn($workspace)?->canManagePeople() === true;

        $status = $request->query('status');
        $status = is_string($status) && LeaveStatus::tryFrom($status) !== null ? $status : null;

        return response()->json(['data' => [
            'leave_types' => LeaveTypeResource::collection(
                $workspace->leaveTypes()->available()->orderBy('name')->get(),
            )->resolve($request),
            'balances' => $employee === null ? [] : LeaveBalances::for($employee, $workspace),
            'my_requests' => $employee === null ? [] : LeaveRequestResource::collection(
                $employee->leaveRequests()->with(self::RELATIONS)->orderByDesc('starts_on')->limit(50)->get(),
            )->resolve($request),
            'queue' => $canManage ? LeaveRequestResource::collection(
                $workspace->leaveRequests()
                    ->with(self::RELATIONS)
                    ->when(
                        $status !== null,
                        fn ($query) => $query->where('status', $status),
                        fn ($query) => $query->where('status', LeaveStatus::Pending),
                    )
                    ->orderBy('starts_on')
                    ->limit(100)
                    ->get(),
            )->resolve($request) : [],
            'filters' => ['status' => $status ?? LeaveStatus::Pending->value],
            'statuses' => LeaveStatus::options(),
            'holidays' => $workspace->holidays()
                ->fallingBetween(today()->startOfYear(), today()->addYear()->endOfYear())
                ->get(['name', 'date', 'is_recurring'])
                ->map(fn ($holiday): array => [
                    'name' => $holiday->name,
                    'date' => $holiday->nextOccurrence(today()->startOfYear())?->toDateString(),
                ])
                ->filter(fn (array $holiday): bool => $holiday['date'] !== null)
                ->values()
                ->all(),
            'can_request' => $user->can('create', [LeaveRequest::class, $workspace]),
            'can_manage' => $canManage,
        ]]);
    }

    public function store(
        Workspace $workspace,
        StoreLeaveRequestRequest $request,
        RequestLeave $requestLeave,
    ): JsonResponse {
        $leave = $requestLeave->handle(
            employee: $request->user()->employeeIn($workspace),
            leaveType: $workspace->leaveTypes()->findOrFail($request->integer('leave_type_id')),
            startsOn: Carbon::parse($request->string('starts_on')->toString())->startOfDay(),
            endsOn: Carbon::parse($request->string('ends_on')->toString())->startOfDay(),
            reason: $request->filled('reason') ? $request->string('reason')->toString() : null,
        );

        return (new LeaveRequestResource($leave->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function cancel(Workspace $workspace, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorize('cancel', $leaveRequest);

        $leaveRequest->update(['status' => LeaveStatus::Cancelled]);

        return new LeaveRequestResource($leaveRequest->load(self::RELATIONS));
    }
}
