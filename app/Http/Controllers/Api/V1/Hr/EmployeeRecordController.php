<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Hr\StoreHrFile;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreEmployeeRecordRequest;
use App\Http\Resources\Hr\EmployeeRecordResource;
use App\Models\Employee;
use App\Models\EmployeeRecord;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * HR's file on somebody: notes, commendations, warnings, reviews, documents.
 * HR-only in both directions — the employee does not see these.
 */
class EmployeeRecordController extends Controller
{
    public function __construct(
        private readonly StoreHrFile $files,
        private readonly RecordAuditLog $audit,
    ) {}

    public function store(Workspace $workspace, Employee $employee, StoreEmployeeRecordRequest $request): JsonResponse
    {
        $attributes = $request->safe()->except('file');

        if ($request->hasFile('file')) {
            $attributes = [...$attributes, ...$this->files->handle($employee, $request->file('file'), 'records')];
        }

        $record = $employee->records()->create([...$attributes, 'recorded_by' => $request->user()->id]);

        $this->audit->handle($workspace, AuditAction::EmployeeRecordCreated, $request->user(), $record, newValues: [
            'employee' => $employee->user->name,
            'type' => $record->type->value,
            'title' => $record->title,
        ]);

        return (new EmployeeRecordResource($record->load('recorder')))->response()->setStatusCode(201);
    }

    public function destroy(
        Workspace $workspace,
        Employee $employee,
        EmployeeRecord $record,
        Request $request,
    ): Response {
        $this->authorize('update', $employee);

        $this->files->delete($record->file_path);
        $record->delete();

        $this->audit->handle($workspace, AuditAction::EmployeeRecordDeleted, $request->user(), $employee, oldValues: [
            'type' => $record->type->value,
            'title' => $record->title,
        ]);

        return response()->noContent();
    }
}
