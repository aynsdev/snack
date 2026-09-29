<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\ClockTime;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ClockRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * The time clock. Two endpoints rather than a toggle, so a double-tapped
 * "Clock in" cannot clock somebody straight back out.
 */
class ClockController extends Controller
{
    public function in(Workspace $workspace, ClockRequest $request, ClockTime $clock): RedirectResponse
    {
        $clock->in($request->user()->employeeIn($workspace), $request->location(), $request->input('note'));

        return back()->with('success', 'Clocked in.');
    }

    public function out(Workspace $workspace, ClockRequest $request, ClockTime $clock): RedirectResponse
    {
        $entry = $clock->out($request->user()->employeeIn($workspace), $request->location(), $request->input('note'));

        $hours = intdiv($entry->minutesWorked(), 60);
        $minutes = $entry->minutesWorked() % 60;

        return back()->with('success', "Clocked out after {$hours}h {$minutes}m.");
    }
}
