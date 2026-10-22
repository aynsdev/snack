<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Hr\StoreHrFile;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ContractRequest;
use App\Http\Resources\Hr\ContractResource;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Contracts on somebody's file, each with an optional signed copy. The copy
 * is downloaded through the web controller — see routes/api/v1/workspace/hr-people.php.
 */
class ContractController extends Controller
{
    public function __construct(
        private readonly StoreHrFile $files,
        private readonly RecordAuditLog $audit,
    ) {}

    public function store(Workspace $workspace, Employee $employee, ContractRequest $request): JsonResponse
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

        return (new ContractResource($contract))->response()->setStatusCode(201);
    }

    public function update(
        Workspace $workspace,
        Employee $employee,
        EmployeeContract $contract,
        ContractRequest $request,
    ): ContractResource {
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

        return new ContractResource($contract);
    }

    public function destroy(
        Workspace $workspace,
        Employee $employee,
        EmployeeContract $contract,
        Request $request,
    ): Response {
        $this->authorize('update', $employee);

        $this->files->delete($contract->file_path);
        $contract->delete();

        $this->audit->handle($workspace, AuditAction::ContractDeleted, $request->user(), $employee, oldValues: [
            'title' => $contract->title,
        ]);

        return response()->noContent();
    }
}
