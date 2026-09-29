<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\UpdateHrSettingsRequest;
use App\Http\Resources\Hr\LeaveTypeResource;
use App\Models\LeaveType;
use App\Models\Workspace;
use App\Support\Geofence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where people may clock in from, and which kinds of leave exist.
 */
class HrSettingsController extends Controller
{
    public function edit(Workspace $workspace, Request $request): Response
    {
        $this->authorize('create', [LeaveType::class, $workspace]);

        LeaveType::seedDefaultsFor($workspace);

        return Inertia::render('hr/settings', [
            'geofence' => Geofence::forWorkspace($workspace)?->toArray(),
            'require_location' => (bool) $workspace->setting('hr.require_location', false),
            'leave_types' => LeaveTypeResource::collection(
                $workspace->leaveTypes()->orderByRaw('archived_at is not null')->orderBy('name')->get(),
            )->resolve($request),
        ]);
    }

    public function update(Workspace $workspace, UpdateHrSettingsRequest $request, RecordAuditLog $audit): RedirectResponse
    {
        $before = $workspace->setting('hr', []);

        $geofence = $request->input('geofence');

        $hr = [
            'require_location' => $request->boolean('require_location'),
            'geofence' => is_array($geofence) ? [
                'latitude' => (float) $geofence['latitude'],
                'longitude' => (float) $geofence['longitude'],
                'radius_meters' => (int) $geofence['radius_meters'],
                'enforced' => (bool) ($geofence['enforced'] ?? false),
            ] : null,
        ];

        $settings = $workspace->settings ?? [];
        $settings['hr'] = $hr;
        $workspace->update(['settings' => $settings]);

        $audit->handle($workspace, AuditAction::HrSettingsUpdated, $request->user(), $workspace, is_array($before) ? $before : [], $hr);

        return back()->with('success', 'HR settings saved.');
    }
}
