<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\ClockTime;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ClockRequest;
use App\Http\Resources\Hr\TimeEntryResource;
use App\Models\Workspace;
use App\Support\Geofence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The time clock. Two endpoints rather than a toggle, so a double-tapped
 * "Clock in" cannot clock somebody straight back out.
 */
class ClockController extends Controller
{
    /**
     * The header clock the web app shares with every page (see
     * HandleInertiaRequests::clockProps): whether you are on the clock, and
     * the location rules a clock-in must meet. `data` is null for anybody
     * without a current employee file, who has no clock to show.
     */
    public function show(Workspace $workspace, Request $request): JsonResponse
    {
        $employee = $request->user()->employeeIn($workspace);

        if ($employee === null || ! $employee->status->isEmployed()) {
            return response()->json(['data' => null]);
        }

        $openEntry = $employee->openTimeEntry;

        return response()->json(['data' => [
            'employee_id' => $employee->id,
            'open_entry' => $openEntry === null ? null : [
                'id' => $openEntry->id,
                'clock_in_at' => $openEntry->clock_in_at->toIso8601String(),
            ],
            'require_location' => (bool) $workspace->setting('hr.require_location', false),
            'geofence' => Geofence::forWorkspace($workspace)?->toArray(),
        ]]);
    }

    public function in(Workspace $workspace, ClockRequest $request, ClockTime $clock): JsonResponse
    {
        $entry = $clock->in($request->user()->employeeIn($workspace), $request->location(), $request->input('note'));

        return (new TimeEntryResource($entry))->response()->setStatusCode(201);
    }

    public function out(Workspace $workspace, ClockRequest $request, ClockTime $clock): TimeEntryResource
    {
        return new TimeEntryResource(
            $clock->out($request->user()->employeeIn($workspace), $request->location(), $request->input('note')),
        );
    }
}
