<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\NotifyHrRequest;
use App\Actions\Hr\ReviewHrRequest;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewHrRequestRequest;
use App\Http\Requests\Hr\StoreShiftRequestRequest;
use App\Models\ShiftRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * Asking for a different shift, and HR deciding it.
 */
class ShiftRequestController extends Controller
{
    public function store(Workspace $workspace, StoreShiftRequestRequest $request, NotifyHrRequest $notify): RedirectResponse
    {
        $employee = $request->user()->employeeIn($workspace);

        $shiftRequest = $employee->shiftRequests()->create([
            ...$request->safe()->only(['shift_id', 'starts_on', 'ends_on', 'reason']),
            'workspace_id' => $workspace->id,
            'status' => RequestStatus::Pending,
        ]);

        $notify->requested($shiftRequest);

        return back()->with('success', 'Shift change requested.');
    }

    public function cancel(Workspace $workspace, ShiftRequest $shiftRequest): RedirectResponse
    {
        $this->authorize('cancel', $shiftRequest);

        $shiftRequest->update(['status' => RequestStatus::Cancelled]);

        return back()->with('success', 'Request withdrawn.');
    }

    public function approve(Workspace $workspace, ShiftRequest $shiftRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): RedirectResponse
    {
        $review->approve($shiftRequest, $request->user(), $request->input('note'));

        return back()->with('success', "Approved {$shiftRequest->employee->user->name}'s {$shiftRequest->kind()}.");
    }

    public function reject(Workspace $workspace, ShiftRequest $shiftRequest, ReviewHrRequestRequest $request, ReviewHrRequest $review): RedirectResponse
    {
        $review->reject($shiftRequest, $request->user(), $request->input('note'));

        return back()->with('success', "Rejected {$shiftRequest->employee->user->name}'s {$shiftRequest->kind()}.");
    }
}
