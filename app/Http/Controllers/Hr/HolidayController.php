<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\HolidayRequest;
use App\Http\Resources\Hr\HolidayResource;
use App\Models\Holiday;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HolidayController extends Controller
{
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [Holiday::class, $workspace]);

        $holidays = $workspace->holidays()->get()
            ->sortBy(fn (Holiday $holiday): string => $holiday->nextOccurrence()?->toDateString() ?? '9999-'.$holiday->date->format('m-d'))
            ->values();

        return Inertia::render('hr/holidays', [
            'holidays' => HolidayResource::collection($holidays)->resolve($request),
            'can_manage' => $request->user()->can('create', [Holiday::class, $workspace]),
        ]);
    }

    public function store(Workspace $workspace, HolidayRequest $request): RedirectResponse
    {
        $workspace->holidays()->create([
            ...$request->validated(),
            'is_recurring' => $request->boolean('is_recurring'),
        ]);

        return back()->with('success', 'Holiday added.');
    }

    public function update(Workspace $workspace, Holiday $holiday, HolidayRequest $request): RedirectResponse
    {
        $holiday->update([
            ...$request->validated(),
            'is_recurring' => $request->boolean('is_recurring'),
        ]);

        return back()->with('success', 'Holiday updated.');
    }

    public function destroy(Workspace $workspace, Holiday $holiday): RedirectResponse
    {
        $this->authorize('delete', $holiday);

        $holiday->delete();

        return back()->with('success', 'Holiday removed.');
    }
}
