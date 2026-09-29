<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\LeaveTypeRequest;
use App\Models\LeaveType;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class LeaveTypeController extends Controller
{
    public function store(Workspace $workspace, LeaveTypeRequest $request): RedirectResponse
    {
        $workspace->leaveTypes()->create($this->attributes($request));

        return back()->with('success', 'Leave type added.');
    }

    public function update(Workspace $workspace, LeaveType $leaveType, LeaveTypeRequest $request): RedirectResponse
    {
        $leaveType->update($this->attributes($request));

        return back()->with('success', 'Leave type updated.');
    }

    /**
     * Deleting a type people have already taken would orphan their history,
     * so a used type is archived instead — it stops being offered, and the
     * requests made against it keep their name.
     */
    public function destroy(Workspace $workspace, LeaveType $leaveType): RedirectResponse
    {
        $this->authorize('delete', $leaveType);

        if ($leaveType->requests()->exists()) {
            $leaveType->update(['archived_at' => now()]);

            return back()->with('success', 'Leave type archived; its past requests are kept.');
        }

        $leaveType->delete();

        return back()->with('success', 'Leave type deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(LeaveTypeRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'color' => $request->string('color')->toString(),
            'days_per_year' => $request->filled('days_per_year') ? $request->input('days_per_year') : null,
            'is_paid' => $request->boolean('is_paid', true),
            'archived_at' => $request->boolean('is_archived') ? now() : null,
        ];
    }
}
