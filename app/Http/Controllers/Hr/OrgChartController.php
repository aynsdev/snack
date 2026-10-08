<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who reports to whom, for everybody in the workspace to read. Built from each
 * employee's manager; the tree itself is assembled in the browser.
 */
class OrgChartController extends Controller
{
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('view', $workspace);

        return Inertia::render('hr/org-chart', [
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
        ]);
    }
}
