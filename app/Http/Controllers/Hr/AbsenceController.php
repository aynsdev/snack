<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreAbsenceRequest;
use App\Models\Absence;
use App\Models\Employee;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AbsenceController extends Controller
{
    public function store(
        Workspace $workspace,
        Employee $employee,
        StoreAbsenceRequest $request,
        RecordAuditLog $audit,
    ): RedirectResponse {
        $absence = $employee->absences()->create([
            ...$request->validated(),
            'workspace_id' => $workspace->id,
            'recorded_by' => $request->user()->id,
        ]);

        $audit->handle($workspace, AuditAction::AbsenceRecorded, $request->user(), $absence, newValues: [
            'employee' => $employee->user->name,
            'date' => $absence->date->toDateString(),
            'type' => $absence->type->value,
        ]);

        return back()->with('success', 'Absence recorded.');
    }

    public function destroy(
        Workspace $workspace,
        Employee $employee,
        Absence $absence,
        Request $request,
        RecordAuditLog $audit,
    ): RedirectResponse {
        $this->authorize('update', $employee);

        $absence->delete();

        $audit->handle($workspace, AuditAction::AbsenceDeleted, $request->user(), $employee, oldValues: [
            'date' => $absence->date->toDateString(),
            'type' => $absence->type->value,
        ]);

        return back()->with('success', 'Absence removed.');
    }
}
