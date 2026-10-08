<?php

namespace App\Http\Controllers\Hr;

use App\Enums\WorkType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hr\HrRequestResource;
use App\Http\Resources\Hr\ShiftResource;
use App\Http\Resources\Hr\TimeEntryResource;
use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\ShiftRequest;
use App\Models\Workspace;
use App\Services\Hr\ShiftResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shift, work-type, and attendance requests on one page: your own, and — for
 * HR — everybody's that are waiting on a decision.
 */
class HrRequestController extends Controller
{
    /** How many of your own past requests are listed. */
    private const HISTORY = 30;

    public function index(Workspace $workspace, Request $request, ShiftResolver $resolver): Response
    {
        $this->authorize('view', $workspace);

        $user = $request->user();
        $employee = $user->employeeIn($workspace);
        $employee?->load(['shift', 'workspace']);
        $canManage = $user->roleIn($workspace)?->canManagePeople() === true;

        return Inertia::render('hr/requests', [
            'schedule' => $employee === null ? null : $this->schedule($employee, $resolver),
            'my_requests' => $employee === null ? [] : HrRequestResource::collection($this->collect(
                fn (string $relation): HasMany => $employee->{$relation}(),
                fn (Builder $query) => $query->latest()->limit(self::HISTORY),
            )->take(self::HISTORY))->resolve($request),
            'queue' => $canManage ? HrRequestResource::collection($this->collect(
                fn (string $relation): HasMany => $workspace->{$relation}(),
                fn (Builder $query) => $query->pending(),
            ))->resolve($request) : [],
            'shifts' => ShiftResource::collection($workspace->shifts()->orderBy('starts_at')->get())->resolve($request),
            'work_types' => WorkType::options(),
            'recent_entries' => $employee === null ? [] : TimeEntryResource::collection(
                $employee->timeEntries()->whereNotNull('clock_out_at')->latest('clock_in_at')->limit(20)->get(),
            )->resolve($request),
            'can_request' => $user->can('create', [ShiftRequest::class, $workspace]),
            'can_manage' => $canManage,
        ]);
    }

    /**
     * All three kinds of request from the same owner, newest first.
     *
     * @param  callable(string): HasMany<covariant HrRequest, covariant \Illuminate\Database\Eloquent\Model>  $relation
     * @param  callable(Builder<covariant HrRequest>): mixed  $constrain
     * @return Collection<int, HrRequest>
     */
    private function collect(callable $relation, callable $constrain): Collection
    {
        return collect([
            ['shiftRequests', ['employee.user', 'reviewer', 'workspace', 'shift']],
            ['workTypeRequests', ['employee.user', 'reviewer', 'workspace']],
            ['attendanceRequests', ['employee.user', 'reviewer', 'workspace']],
        ])
            ->flatMap(fn (array $kind) => $relation($kind[0])->with($kind[1])->tap($constrain)->get())
            ->sortByDesc(fn (HrRequest $request): int => $request->created_at?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * The shift and work type somebody is on today, and on file.
     *
     * @return array<string, mixed>
     */
    private function schedule(Employee $employee, ShiftResolver $resolver): array
    {
        $today = $resolver->shiftFor($employee, today());

        return [
            'today_shift' => $today === null ? null : (new ShiftResource($today))->resolve(),
            'today_works' => $today?->worksOn(today()) ?? false,
            'today_work_type' => $resolver->workTypeFor($employee, today())->label(),
            'work_type' => $employee->work_type->value,
            'work_type_label' => $employee->work_type->label(),
            'pending' => $employee->shiftRequests()->pending()->count()
                + $employee->workTypeRequests()->pending()->count()
                + $employee->attendanceRequests()->pending()->count(),
        ];
    }
}
