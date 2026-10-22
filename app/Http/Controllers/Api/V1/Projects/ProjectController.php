<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\SetProjectArchived;
use App\Actions\Projects\UpdateProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\AssignProjectGroupRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectGroupResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\UserResource;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The project list, and a project's own settings — the app's side of
 * {@see \App\Http\Controllers\Projects\ProjectController}.
 *
 * Writes answer with the saved project rather than a redirect. That matters
 * most for a rename: it regenerates the slug, and the app has to learn the new
 * one from somewhere before its next request names the project.
 */
class ProjectController extends Controller
{
    public function __construct(private readonly ProjectBoard $boards) {}

    /**
     * Every project in the workspace, the headings they sit under, and the
     * archived ones — which the web defers and the app gets in the same answer.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [Project::class, $workspace]);

        $user = $request->user();

        return response()->json([
            'data' => [
                'projects' => ProjectResource::collection($this->projects($workspace, $user, archived: false))->resolve($request),
                'groups' => ProjectGroupResource::collection($workspace->projectGroups()->ordered()->get())->resolve($request),
                'archived' => ProjectResource::collection($this->projects($workspace, $user, archived: true))->resolve($request),
                'can_create' => $user->can('create', [Project::class, $workspace]),
                'can_manage_groups' => $user->can('create', [ProjectGroup::class, $workspace]),
            ],
        ]);
    }

    public function store(
        Workspace $workspace,
        StoreProjectRequest $request,
        CreateProject $createProject,
    ): JsonResponse {
        $project = $createProject->handle(
            workspace: $workspace,
            creator: $request->user(),
            attributes: [
                'name' => $request->string('name')->toString(),
                'key' => $request->input('key'),
                'description' => $request->input('description'),
                'color' => $request->input('color'),
                'uses_sprints' => $request->boolean('uses_sprints', true),
                'project_group_id' => $request->integer('project_group_id') ?: null,
            ],
        );

        return (new ProjectResource($project->loadCount(['members', 'tasks'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The settings page's props: the project, the shelves it could sit on, its
     * people and labels, and who could still be added.
     */
    public function settings(Workspace $workspace, Project $project, Request $request): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => [
                'project' => (new ProjectResource($project->loadCount(['members', 'tasks'])))->resolve($request),
                'groups' => ProjectGroupResource::collection($workspace->projectGroups()->ordered()->get())->resolve($request),
                'members' => $this->boards->members($project),
                'labels' => $project->labels()->get()
                    ->map(fn ($label): array => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color])
                    ->all(),
                'can_manage' => $request->user()->can('update', $project),
                'addable' => $this->addableMembers($workspace, $project, $request),
            ],
        ]);
    }

    public function update(
        Workspace $workspace,
        Project $project,
        UpdateProjectRequest $request,
        UpdateProject $updateProject,
    ): ProjectResource {
        $updated = $updateProject->handle(
            project: $project,
            actor: $request->user(),
            attributes: $request->safe()->only([
                'name', 'key', 'description', 'color', 'uses_sprints', 'card_fields',
                'next_task_number',
            ]),
        );

        return new ProjectResource($updated->loadCount(['members', 'tasks']));
    }

    public function assignGroup(
        Workspace $workspace,
        Project $project,
        AssignProjectGroupRequest $request,
        UpdateProject $updateProject,
    ): ProjectResource {
        $updated = $updateProject->handle(
            project: $project,
            actor: $request->user(),
            attributes: ['project_group_id' => $request->integer('project_group_id') ?: null],
        );

        return new ProjectResource($updated);
    }

    public function archive(
        Workspace $workspace,
        Project $project,
        Request $request,
        SetProjectArchived $setArchived,
    ): ProjectResource {
        $this->authorize('archive', $project);

        return new ProjectResource($setArchived->handle($project, $request->user(), archived: true));
    }

    public function unarchive(
        Workspace $workspace,
        Project $project,
        Request $request,
        SetProjectArchived $setArchived,
    ): ProjectResource {
        $this->authorize('archive', $project);

        return new ProjectResource($setArchived->handle($project, $request->user(), archived: false));
    }

    /**
     * The list's projects, open or archived. A guest sees only what they were
     * added to, matching ProjectPolicy::view, and every project is handed the
     * workspace already loaded so the resource's ability checks do not lazy-load
     * it once per row.
     *
     * @return Collection<int, Project>
     */
    private function projects(Workspace $workspace, User $user, bool $archived): Collection
    {
        $isGuest = $user->roleIn($workspace)?->isGuest() === true;

        return $workspace->projects()
            ->where('is_archived', $archived)
            ->when($isGuest, fn ($query) => $query->whereMember($user))
            ->withCount(['members', 'tasks'])
            ->with(['members' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('name')
            ->get()
            ->each(fn (Project $project) => $project->setRelation('workspace', $workspace));
    }

    /**
     * Everyone in the workspace who is not on the project yet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function addableMembers(Workspace $workspace, Project $project, Request $request): array
    {
        return $workspace->activeUsers()
            ->whereNotIn('users.id', $project->members()->pluck('user_id'))
            ->orderBy('users.name')
            ->limit(200)
            ->get()
            ->map(fn (User $user): array => (new UserResource($user))->resolve($request))
            ->all();
    }
}
