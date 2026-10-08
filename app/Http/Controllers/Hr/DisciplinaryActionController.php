<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Hr\StoreHrFile;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreDisciplinaryActionRequest;
use App\Models\DisciplinaryAction;
use App\Models\Employee;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Warnings, suspensions, and dismissals on an employee's file.
 */
class DisciplinaryActionController extends Controller
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly StoreHrFile $files,
    ) {}

    public function store(Workspace $workspace, Employee $employee, StoreDisciplinaryActionRequest $request): RedirectResponse
    {
        $action = $employee->disciplinaryActions()->create([
            ...$request->action(),
            ...($request->hasFile('file') ? $this->files->handle($employee, $request->file('file'), 'disciplinary') : []),
            'workspace_id' => $workspace->id,
            'issued_by' => $request->user()->id,
        ]);

        $this->audit->handle($workspace, AuditAction::DisciplinaryActionIssued, $request->user(), $employee, newValues: [
            'employee' => $employee->user->name,
            'type' => $action->type->value,
            'starts_on' => $action->starts_on->toDateString(),
        ]);

        return back()->with('success', "{$action->type->label()} recorded.");
    }

    public function destroy(Workspace $workspace, Employee $employee, DisciplinaryAction $disciplinaryAction, Request $request): RedirectResponse
    {
        $this->authorize('delete', $disciplinaryAction);

        $disciplinaryAction->delete();
        $this->files->delete($disciplinaryAction->file_path);

        $this->audit->handle($workspace, AuditAction::DisciplinaryActionDeleted, $request->user(), $employee, oldValues: [
            'employee' => $employee->user->name,
            'type' => $disciplinaryAction->type->value,
            'starts_on' => $disciplinaryAction->starts_on->toDateString(),
        ]);

        return back()->with('success', 'Disciplinary action removed.');
    }

    public function download(Workspace $workspace, Employee $employee, DisciplinaryAction $disciplinaryAction): StreamedResponse
    {
        $this->authorize('view', $employee);

        abort_if($disciplinaryAction->file_path === null, 404);

        return Storage::disk(StoreHrFile::disk())->download($disciplinaryAction->file_path, $disciplinaryAction->file_name);
    }
}
