<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\LeaveTypeRequest;
use App\Http\Resources\Hr\LeaveTypeResource;
use App\Models\LeaveType;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The kinds of leave a workspace offers. The web app edits these from HR
 * settings; the list is its own endpoint here so a client can manage them
 * without loading the whole settings page.
 */
class LeaveTypeController extends Controller
{
    /**
     * Every member sees the types on offer; people managers also see the
     * archived ones, which they can restore.
     */
    public function index(Workspace $workspace, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [LeaveType::class, $workspace]);

        LeaveType::seedDefaultsFor($workspace);

        $canManage = $request->user()->can('create', [LeaveType::class, $workspace]);

        return LeaveTypeResource::collection(
            $workspace->leaveTypes()
                ->when(! $canManage, fn ($query) => $query->available())
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(Workspace $workspace, LeaveTypeRequest $request): JsonResponse
    {
        $leaveType = $workspace->leaveTypes()->create($this->attributes($request));

        return (new LeaveTypeResource($leaveType))->response()->setStatusCode(201);
    }

    public function update(Workspace $workspace, LeaveType $leaveType, LeaveTypeRequest $request): LeaveTypeResource
    {
        $leaveType->update($this->attributes($request));

        return new LeaveTypeResource($leaveType);
    }

    /**
     * Deleting a type people have already taken would orphan their history,
     * so a used type is archived instead and returned (200, `is_archived`
     * true); an unused one is deleted outright (204).
     */
    public function destroy(Workspace $workspace, LeaveType $leaveType): Response|LeaveTypeResource
    {
        $this->authorize('delete', $leaveType);

        if ($leaveType->requests()->exists()) {
            $leaveType->update(['archived_at' => now()]);

            return new LeaveTypeResource($leaveType);
        }

        $leaveType->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(LeaveTypeRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'color' => $request->string('color')->toString(),
            'days_per_year' => $request->filled('days_per_year') ? $request->input('days_per_year') : null,
            'is_paid' => $request->boolean('is_paid', true),
            'archived_at' => $request->boolean('is_archived') ? now() : null,
        ];
    }
}
