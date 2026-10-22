<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Projects\SaveRelease;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ReleaseResource;
use App\Models\Project;
use App\Models\Release;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * What ships, and when — see {@see \App\Http\Controllers\Projects\ReleaseController}.
 *
 * Every release in an answer carries its progress counts, so the app can draw
 * the progress bar from whatever it was last handed.
 */
class ReleaseController extends Controller
{
    public function __construct(private readonly ProjectBoard $boards) {}

    public function index(Workspace $workspace, Project $project, Request $request): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => [
                'project' => (new ProjectResource($project))->resolve($request),
                'releases' => ReleaseResource::collection(
                    $project->releases()->withCount($this->progressCounts())->ordered()->get(),
                )->resolve($request),
                'members' => $this->boards->members($project),
                'can_contribute' => $request->user()->can('contribute', $project),
                'can_manage' => $request->user()->can('update', $project),
            ],
        ]);
    }

    /**
     * One release and its work, plus `schedulable`: the project's cards not yet
     * in any release, for the picker that adds them.
     */
    public function show(Workspace $workspace, Project $project, Release $release, Request $request): JsonResponse
    {
        $this->authorize('view', $project);

        $release->loadCount($this->progressCounts())
            ->load(['tasks' => fn ($query) => $query->orderBy('number')]);

        return response()->json([
            'data' => [
                'project' => (new ProjectResource($project))->resolve($request),
                'release' => (new ReleaseResource($release))->resolve($request),
                'members' => $this->boards->members($project),
                'schedulable' => $project->tasks()
                    ->whereNull('release_id')
                    ->orderBy('number')
                    ->get()
                    ->map(fn (Task $task): array => [
                        'id' => $task->id,
                        'reference' => $task->reference(),
                        'title' => $task->title,
                    ])
                    ->all(),
                'can_contribute' => $request->user()->can('contribute', $project),
                'can_manage' => $request->user()->can('update', $project),
            ],
        ]);
    }

    public function store(Workspace $workspace, Project $project, Request $request, SaveRelease $saveRelease): JsonResponse
    {
        $this->authorize('manageSprints', $project);

        $release = $saveRelease->create($project, $this->validated($request, $project));

        return (new ReleaseResource($release->loadCount($this->progressCounts())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        Workspace $workspace,
        Project $project,
        Release $release,
        Request $request,
        SaveRelease $saveRelease,
    ): ReleaseResource {
        $this->authorize('manageSprints', $project);

        $saved = $saveRelease->update($release, $this->validated($request, $project, $release));

        return new ReleaseResource($saved->loadCount($this->progressCounts()));
    }

    /**
     * Marks it as gone out. Idempotent: a second call keeps the first date.
     */
    public function release(Workspace $workspace, Project $project, Release $release, SaveRelease $saveRelease): ReleaseResource
    {
        $this->authorize('manageSprints', $project);

        return new ReleaseResource($saveRelease->markReleased($release, true)->loadCount($this->progressCounts()));
    }

    public function unrelease(Workspace $workspace, Project $project, Release $release, SaveRelease $saveRelease): ReleaseResource
    {
        $this->authorize('manageSprints', $project);

        return new ReleaseResource($saveRelease->markReleased($release, false)->loadCount($this->progressCounts()));
    }

    /**
     * Cancels the release. Its work survives, unscheduled.
     */
    public function destroy(Workspace $workspace, Project $project, Release $release, SaveRelease $saveRelease): Response
    {
        $this->authorize('manageSprints', $project);

        $saveRelease->delete($release);

        return response()->noContent();
    }

    /**
     * @return array<int|string, callable|string>
     */
    private function progressCounts(): array
    {
        return [
            'tasks',
            'tasks as completed_tasks_count' => fn ($query) => $query->whereNotNull('completed_at'),
        ];
    }

    /**
     * The web's rules, unchanged: names unique per project, case-insensitively.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Project $project, ?Release $release = null): array
    {
        return $request->validate([
            'name' => [
                $release === null ? 'required' : 'sometimes',
                'required',
                'string',
                'max:40',
                Rule::unique('releases', 'name')
                    ->where('project_id', $project->id)
                    ->ignore($release?->id),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
        ], [
            'name.unique' => 'This project already has a release by that name.',
            'ends_on.after_or_equal' => 'A release cannot go out before it starts.',
        ]);
    }
}
