<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ShiftRequest;
use App\Http\Resources\Hr\ShiftResource;
use App\Models\Shift;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The shifts a workspace schedules people on. The web app lists them on the
 * HR settings page; the list is its own endpoint here.
 */
class ShiftController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Shift::class, $workspace]);

        return ShiftResource::collection($workspace->shifts()->orderBy('starts_at')->get());
    }

    /**
     * Making a shift the default takes the flag off every other one.
     */
    public function store(Workspace $workspace, ShiftRequest $request): JsonResponse
    {
        $shift = DB::transaction(function () use ($workspace, $request): Shift {
            $attributes = $request->shift();

            if ($attributes['is_default']) {
                $workspace->shifts()->update(['is_default' => false]);
            }

            return $workspace->shifts()->create($attributes);
        });

        $this->audit->handle($workspace, AuditAction::ShiftCreated, $request->user(), $shift, newValues: $request->shift());

        return (new ShiftResource($shift->refresh()))->response()->setStatusCode(201);
    }

    public function update(Workspace $workspace, Shift $shift, ShiftRequest $request): ShiftResource
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

        return new ShiftResource($shift->refresh());
    }

    /**
     * People on the shift fall back to the default; past time entries keep
     * their measurements but lose the link.
     */
    public function destroy(Workspace $workspace, Shift $shift, Request $request): Response
    {
        $this->authorize('delete', $shift);

        $shift->delete();

        $this->audit->handle($workspace, AuditAction::ShiftDeleted, $request->user(), $workspace, oldValues: ['name' => $shift->name]);

        return response()->noContent();
    }
}
