<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\SaveRelease;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ReleaseResource;
use App\Models\Project;
use App\Models\Release;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What ships, and when.
 *
 * A project's third view, beside the board and the backlog: the board answers
 * "what are we working on", the backlog "what is coming", and this "what goes
 * out in 1.4.0".
 *
 * Two pages rather than one. The index is a table — every version, its status,
 * how far along it is, its dates — which is the view somebody scanning a
 * release history wants; the contents of any one of them are a page deeper,
 * because a table row cannot hold forty cards and a document.
 *
 * Inertia redirects rather than JSON, like the sprint endpoints: every write
 * here changes the page as a whole rather than one card on it.
 */
class ReleaseController extends Controller
{
    public function __construct(private readonly ProjectBoard $boards) {}

    public function index(Workspace $workspace, Project $project, Request $request): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/releases', [
            'project' => (new ProjectResource($project))->resolve($request),

            /*
             * Counts, not contents. The table draws a progress bar per row, and
             * loading every card of every version to compute two numbers would
             * be several board-sized payloads for one screen.
             */
            'releases' => ReleaseResource::collection(
                $project->releases()->withCount($this->progressCounts())->ordered()->get(),
            )->resolve($request),

            'members' => $this->boards->members($project),

            'canContribute' => $request->user()->can('contribute', $project),
            'canManage' => $request->user()->can('update', $project),
        ]);
    }

    /**
     * One release, and the work going out in it.
     */
    public function show(
        Workspace $workspace,
        Project $project,
        Release $release,
        Request $request,
    ): Response {
        $this->authorize('view', $project);

        $release->loadCount($this->progressCounts());

        return Inertia::render('projects/release', [
            'project' => (new ProjectResource($project))->resolve($request),

            'release' => (new ReleaseResource(
                $release->load(['tasks' => fn ($query) => $query->orderBy('number')]),
            ))->resolve($request),

            'members' => $this->boards->members($project),

            /*
             * Everything that could go into this release, fetched only for the
             * person who opened the picker. A project's whole unscheduled list
             * is a large payload to send to everyone who glanced at a version.
             */
            'schedulable' => Inertia::optional(fn (): array => $project->tasks()
                ->whereNull('release_id')
                ->orderBy('number')
                ->get()
                ->map(fn (Task $task): array => [
                    'id' => $task->id,
                    'reference' => $task->reference(),
                    'title' => $task->title,
                ])
                ->all()),

            'canContribute' => $request->user()->can('contribute', $project),
            'canManage' => $request->user()->can('update', $project),
        ]);
    }

    public function store(
        Workspace $workspace,
        Project $project,
        Request $request,
        SaveRelease $saveRelease,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        $release = $saveRelease->create($project, $this->validated($request, $project));

        return back()->with('success', "{$release->name} planned.");
    }

    public function update(
        Workspace $workspace,
        Project $project,
        Release $release,
        Request $request,
        SaveRelease $saveRelease,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        $saveRelease->update($release, $this->validated($request, $project, $release));

        return back()->with('success', 'Release updated.');
    }

    /**
     * Marking a version as gone out.
     *
     * Its own endpoint rather than a field on {@see update()}, for the reason
     * archiving a channel is: it is not an edit, it is the thing the whole page
     * is about, and a double-submitted "Release" must not shift the date it
     * already recorded — {@see SaveRelease::markReleased()} keeps the first one.
     */
    public function release(
        Workspace $workspace,
        Project $project,
        Release $release,
        SaveRelease $saveRelease,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        $saveRelease->markReleased($release, true);

        return back()->with('success', "{$release->name} released.");
    }

    public function unrelease(
        Workspace $workspace,
        Project $project,
        Release $release,
        SaveRelease $saveRelease,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        $saveRelease->markReleased($release, false);

        return back()->with('success', "{$release->name} is unreleased again.");
    }

    /**
     * Cancelling a release. The work in it survives — see {@see SaveRelease::delete()}.
     */
    public function destroy(
        Workspace $workspace,
        Project $project,
        Release $release,
        SaveRelease $saveRelease,
    ): RedirectResponse {
        $this->authorize('manageSprints', $project);

        $saveRelease->delete($release);

        return to_route('projects.releases.index', [
            'workspace' => $workspace->slug,
            'project' => $project->slug,
        ])->with('success', 'Release cancelled. Its work is unscheduled.');
    }

    /**
     * How full a release is: everything in it, and how much of that is done.
     *
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

                // Per project, and citext in the database: a team quoting
                // "1.4.0" must not have to ask which 1.4.0.
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
