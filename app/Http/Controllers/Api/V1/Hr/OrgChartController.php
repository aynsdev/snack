<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who reports to whom, for everybody in the workspace. A flat list linked by
 * `manager_id`; the client assembles the tree, as the browser does.
 */
class OrgChartController extends Controller
{
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('view', $workspace);

        return response()->json(['data' => [
            'people' => $workspace->employees()
                ->employed()
                ->with('user')
                ->get()
                ->map(fn (Employee $employee): array => [
                    'id' => $employee->id,
                    'manager_id' => $employee->manager_id,
                    'name' => $employee->user->name,
                    'initials' => $employee->user->initials(),
                    'avatar_url' => $employee->user->avatarUrl(),
                    'job_title' => $employee->job_title,
                    'department' => $employee->department,
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            'own_employee_id' => $request->user()->employeeIn($workspace)?->id,
        ]]);
    }
}
