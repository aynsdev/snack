<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\UpdateHrSettingsRequest;
use App\Http\Resources\Hr\ChecklistTemplateResource;
use App\Http\Resources\Hr\LeaveTypeResource;
use App\Http\Resources\Hr\ShiftResource;
use App\Models\ChecklistTemplate;
use App\Models\LeaveType;
use App\Models\Workspace;
use App\Support\Geofence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where people may clock in from, which kinds of leave exist, the shifts
 * people are scheduled on, and how onboarding and offboarding go.
 */
class HrSettingsController extends Controller
{
    public function edit(Workspace $workspace, Request $request): Response
    {
        $this->authorize('create', [LeaveType::class, $workspace]);

        LeaveType::seedDefaultsFor($workspace);
        ChecklistTemplate::seedDefaultsFor($workspace);

        return Inertia::render('hr/settings', [
            'geofence' => Geofence::forWorkspace($workspace)?->toArray(),
            'require_location' => (bool) $workspace->setting('hr.require_location', false),
            'leave_types' => LeaveTypeResource::collection(
                $workspace->leaveTypes()->orderByRaw('archived_at is not null')->orderBy('name')->get(),
            )->resolve($request),
            'shifts' => ShiftResource::collection($workspace->shifts()->orderBy('starts_at')->get())->resolve($request),
            'checklist_templates' => ChecklistTemplateResource::collection(
                $workspace->checklistTemplates()->with('tasks')->orderBy('kind')->orderByDesc('is_default')->orderBy('name')->get(),
            )->resolve($request),
            'auto_onboarding' => (bool) $workspace->setting('hr.auto_onboarding', false),
        ]);
    }

    public function update(Workspace $workspace, UpdateHrSettingsRequest $request, RecordAuditLog $audit): RedirectResponse
    {
        $before = $workspace->setting('hr', []);

        $hr = is_array($before) ? $before : [];

        // Each settings form sends only its own fields; whatever it leaves out
        // keeps its saved value.
        if ($request->has('require_location')) {
            $hr['require_location'] = $request->boolean('require_location');
        }

        if ($request->exists('geofence')) {
            $geofence = $request->input('geofence');

            $hr['geofence'] = is_array($geofence) ? [
                'latitude' => (float) $geofence['latitude'],
                'longitude' => (float) $geofence['longitude'],
                'radius_meters' => (int) $geofence['radius_meters'],
                'enforced' => (bool) ($geofence['enforced'] ?? false),
            ] : null;
        }

        if ($request->has('auto_onboarding')) {
            $hr['auto_onboarding'] = $request->boolean('auto_onboarding');
        }

        $settings = $workspace->settings ?? [];
        $settings['hr'] = $hr;
        $workspace->update(['settings' => $settings]);

        $audit->handle($workspace, AuditAction::HrSettingsUpdated, $request->user(), $workspace, is_array($before) ? $before : [], $hr);

        return back()->with('success', 'HR settings saved.');
    }
}
