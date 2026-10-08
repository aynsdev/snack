<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\NotifyHrRequest;
use App\Actions\Hr\ReviewHrRequest;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewHrRequestRequest;
use App\Http\Requests\Hr\StoreWorkTypeRequestRequest;
use App\Models\Workspace;
use App\Models\WorkTypeRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Asking to work remote, on-site, or hybrid, and HR deciding it.
 */
class WorkTypeRequestController extends Controller
{
    public function store(Workspace $workspace, StoreWorkTypeRequestRequest $request, NotifyHrRequest $notify): RedirectResponse
    {
        $employee = $request->user()->employeeIn($workspace);

        $workTypeRequest = $employee->workTypeRequests()->create([
            ...$request->safe()->only(['work_type', 'starts_on', 'ends_on', 'reason']),
            'workspace_id' => $workspace->id,
            'status' => RequestStatus::Pending,
        ]);

        $notify->requested($workTypeRequest);

        return back()->with('success', 'Work type change requested.');
    }

    public function cancel(Workspace $workspace, WorkTypeRequest $workTypeRequest): RedirectResponse
    {
        $this->authorize('cancel', $workTypeRequest);

        $workTypeRequest->update(['status' => RequestStatus::Cancelled]);

        return back()->with('success', 'Request withdrawn.');
    }

    public function approve(Workspace $workspace, WorkTypeRequest $workTypeRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): RedirectResponse
    {
        $review->approve($workTypeRequest, $request->user(), $request->input('note'));

        return back()->with('success', "Approved {$workTypeRequest->employee->user->name}'s {$workTypeRequest->kind()}.");
    }

    public function reject(Workspace $workspace, WorkTypeRequest $workTypeRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): RedirectResponse
    {
        $review->reject($workTypeRequest, $request->user(), $request->input('note'));

        return back()->with('success', "Rejected {$workTypeRequest->employee->user->name}'s {$workTypeRequest->kind()}.");
    }
}
