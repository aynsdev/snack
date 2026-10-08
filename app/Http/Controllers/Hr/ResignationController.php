<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\NotifyChecklist;
use App\Actions\Hr\ReviewResignation;
use App\Enums\ResignationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewResignationRequest;
use App\Http\Requests\Hr\StoreResignationRequest;
use App\Models\Resignation;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * Giving notice, and HR accepting it — which starts offboarding.
 */
class ResignationController extends Controller
{
    public function store(Workspace $workspace, StoreResignationRequest $request, NotifyChecklist $notify): RedirectResponse
    {
        $resignation = $request->user()->employeeIn($workspace)->resignations()->create([
            'workspace_id' => $workspace->id,
            'reason' => $request->validated('reason'),
            'last_day_on' => $request->validated('last_day_on'),
            'status' => ResignationStatus::Pending,
        ]);

        $notify->resigned($resignation);

        return back()->with('success', 'Your resignation has been sent to HR.');
    }

    public function withdraw(Workspace $workspace, Resignation $resignation): RedirectResponse
    {
        $this->authorize('withdraw', $resignation);

        $resignation->update(['status' => ResignationStatus::Withdrawn]);

        return back()->with('success', 'Resignation withdrawn.');
    }

    public function accept(Workspace $workspace, Resignation $resignation, ReviewResignationRequest $request, ReviewResignation $review): RedirectResponse
    {
        $review->accept($resignation, $request->user(), $request->validated('note'));

        return back()->with('success', "Accepted {$resignation->employee->user->name}'s resignation. Offboarding has started.");
    }

    public function reject(Workspace $workspace, Resignation $resignation, ReviewResignationRequest $request, ReviewResignation $review): RedirectResponse
    {
        $review->reject($resignation, $request->user(), $request->validated('note'));

        return back()->with('success', 'Resignation rejected.');
    }
}
