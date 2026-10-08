<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ShiftRequest;
use App\Models\Shift;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The shifts a workspace schedules people on. Managed from HR settings.
 */
class ShiftController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function store(Workspace $workspace, ShiftRequest $request): RedirectResponse
    {
        $shift = DB::transaction(function () use ($workspace, $request): Shift {
            $attributes = $request->shift();

            if ($attributes['is_default']) {
                $workspace->shifts()->update(['is_default' => false]);
            }

            return $workspace->shifts()->create($attributes);
        });

        $this->audit->handle($workspace, AuditAction::ShiftCreated, $request->user(), $shift, newValues: $request->shift());

        return back()->with('success', "Shift {$shift->name} created.");
    }

    public function update(Workspace $workspace, Shift $shift, ShiftRequest $request): RedirectResponse
    {
        $before = $shift->only(['name', 'starts_at', 'ends_at', 'grace_minutes', 'days', 'is_default']);

        DB::transaction(function () use ($workspace, $shift, $request): void {
            $attributes = $request->shift();

            if ($attributes['is_default']) {
                $workspace->shifts()->whereKeyNot($shift->id)->update(['is_default' => false]);
            }

            $shift->update($attributes);
        });

        $this->audit->handle($workspace, AuditAction::ShiftUpdated, $request->user(), $shift, $before, $request->shift());

        return back()->with('success', 'Shift updated.');
    }

    /**
     * People on the shift fall back to the default; past time entries keep
     * their measurements but lose the link.
     */
    public function destroy(Workspace $workspace, Shift $shift, Request $request): RedirectResponse
    {
        $this->authorize('delete', $shift);

        $shift->delete();

        $this->audit->handle($workspace, AuditAction::ShiftDeleted, $request->user(), $workspace, oldValues: ['name' => $shift->name]);

        return back()->with('success', 'Shift deleted.');
    }
}
