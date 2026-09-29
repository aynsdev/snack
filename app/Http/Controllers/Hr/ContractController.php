<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Hr\StoreHrFile;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ContractRequest;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    public function __construct(
        private readonly StoreHrFile $files,
        private readonly RecordAuditLog $audit,
    ) {}

    public function store(Workspace $workspace, Employee $employee, ContractRequest $request): RedirectResponse
    {
        $attributes = $request->safe()->except('file');

        if ($request->hasFile('file')) {
            $attributes = [...$attributes, ...$this->files->handle($employee, $request->file('file'), 'contracts')];
        }

        $contract = $employee->contracts()->create([...$attributes, 'created_by' => $request->user()->id]);

        $this->audit->handle($workspace, AuditAction::ContractCreated, $request->user(), $contract, newValues: [
            'employee' => $employee->user->name,
            'title' => $contract->title,
        ]);

        return back()->with('success', 'Contract added.');
    }

    public function update(
        Workspace $workspace,
        Employee $employee,
        EmployeeContract $contract,
        ContractRequest $request,
    ): RedirectResponse {
        $attributes = $request->safe()->except('file');

        if ($request->hasFile('file')) {
            $this->files->delete($contract->file_path);
            $attributes = [...$attributes, ...$this->files->handle($employee, $request->file('file'), 'contracts')];
        }

        $contract->update($attributes);

        $this->audit->handle($workspace, AuditAction::ContractUpdated, $request->user(), $contract, newValues: [
            'employee' => $employee->user->name,
            'title' => $contract->title,
            'status' => $contract->status->value,
        ]);

        return back()->with('success', 'Contract updated.');
    }

    public function destroy(
        Workspace $workspace,
        Employee $employee,
        EmployeeContract $contract,
        Request $request,
    ): RedirectResponse {
        $this->authorize('update', $employee);

        $this->files->delete($contract->file_path);
        $contract->delete();

        $this->audit->handle($workspace, AuditAction::ContractDeleted, $request->user(), $employee, oldValues: [
            'title' => $contract->title,
        ]);

        return back()->with('success', 'Contract deleted.');
    }

    /**
     * The signed copy. Readable by HR and by the employee it belongs to.
     */
    public function download(Workspace $workspace, Employee $employee, EmployeeContract $contract): StreamedResponse
    {
        $this->authorize('view', $employee);

        abort_if($contract->file_path === null, 404);

        return Storage::disk(StoreHrFile::disk())->download($contract->file_path, $contract->file_name);
    }
}
