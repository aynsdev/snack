<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\HolidayRequest;
use App\Http\Resources\Hr\HolidayResource;
use App\Models\Holiday;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The workspace's holidays, which leave requests skip when counting days.
 */
class HolidayController extends Controller
{
    /**
     * Soonest first, by next occurrence, with `can_manage` alongside.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [Holiday::class, $workspace]);

        $holidays = $workspace->holidays()->get()
            ->sortBy(fn (Holiday $holiday): string => $holiday->nextOccurrence()?->toDateString() ?? '9999-'.$holiday->date->format('m-d'))
            ->values();

        return HolidayResource::collection($holidays)
            ->additional(['can_manage' => $request->user()->can('create', [Holiday::class, $workspace])])
            ->response();
    }

    public function store(Workspace $workspace, HolidayRequest $request): JsonResponse
    {
        $holiday = $workspace->holidays()->create([
            ...$request->validated(),
            'is_recurring' => $request->boolean('is_recurring'),
        ]);

        return (new HolidayResource($holiday))->response()->setStatusCode(201);
    }

    public function update(Workspace $workspace, Holiday $holiday, HolidayRequest $request): HolidayResource
    {
        $holiday->update([
            ...$request->validated(),
            'is_recurring' => $request->boolean('is_recurring'),
        ]);

        return new HolidayResource($holiday);
    }

    public function destroy(Workspace $workspace, Holiday $holiday): Response
    {
        $this->authorize('delete', $holiday);

        $holiday->delete();

        return response()->noContent();
    }
}
