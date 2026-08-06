<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\SetProjectArchived;
use App\Actions\Projects\UpdateProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\AssignProjectGroupRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\BoardColumnResource;
use App\Http\Resources\ProjectGroupResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\UserResource;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\Workspace;
use App\Services\ProjectBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project list, and a project's own settings.
 *
 * Every action declares {workspace} first, matching the route's parameter order
 * — Laravel passes route parameters positionally, so omitting it on a nested
 * route shifts every subsequent argument by one.
 */
class ProjectController extends Controller
{
    public function __construct(private readonly ProjectBoard $boards) {}

    /**
     * Every project in the workspace, with the viewer's own first.
     */
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [Project::class, $workspace]);

        $user = $request->user();
        $isGuest = $user->roleIn($workspace)?->isGuest() === true;

        $projects = $workspace->projects()
            ->notArchived()
            // A guest sees only what they were added to, matching
            // ProjectPolicy::view — otherwise this list would be a directory of
            // boards every one of which 404s when opened.
            ->when($isGuest, fn ($query) => $query->whereMember($user))
            ->withCount(['members', 'tasks'])
            ->with(['members' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('name')
            ->get()
            /*
             * Hand every project the workspace instance we already have.
             *
             * ProjectResource asks the policy what this viewer may do, and
             * ProjectPolicy answers by reading the project's workspace — a lazy
             * load per project otherwise, which is how a list of thirty boards
             * became thirty extra queries. Same fix as
             * {@see App\Services\MessageHistory::shareContainer()}.
             */
            ->each(fn (Project $project) => $project->setRelation('workspace', $workspace));

        return Inertia::render('projects/index', [
            'projects' => ProjectResource::collection($projects)->resolve($request),

            /*
             * The headings, sent flat and in order rather than with their
             * projects nested inside them. The client buckets — which keeps one
             * project one object here, and means a group with nothing in it is
             * still a heading somebody can drop a project onto instead of
             * vanishing from a list built out of projects.
             */
            'groups' => ProjectGroupResource::collection(
                $workspace->projectGroups()->ordered()->get(),
            )->resolve($request),

            'canCreate' => $user->can('create', [Project::class, $workspace]),
            'canManageGroups' => $user->can('create', [ProjectGroup::class, $workspace]),

            /*
             * Archived projects are deferred: they are the reason somebody opens
             * this page perhaps once a quarter, and loading them would put a
             * second query on the page everyone opens daily.
             */
            'archived' => Inertia::defer(fn (): array => ProjectResource::collection(
                $workspace->projects()
                    ->where('is_archived', true)
                    ->when($isGuest, fn ($query) => $query->whereMember($user))
                    ->withCount(['members', 'tasks'])
                    ->orderBy('name')
                    ->get()
                    ->each(fn (Project $project) => $project->setRelation('workspace', $workspace)),
            )->resolve($request)),
        ]);
    }

    public function store(
        Workspace $workspace,
        StoreProjectRequest $request,
        CreateProject $createProject,
    ): RedirectResponse {
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

        return to_route('projects.board', [
            'workspace' => $workspace->slug,
            'project' => $project->slug,
        ])->with('success', "{$project->name} created.");
    }

    /**
     * A project's own settings: what it is called, how its cards are drawn, and
     * who is on it.
     */
    public function edit(Workspace $workspace, Project $project, Request $request): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/settings', [
            'project' => (new ProjectResource($project->loadCount(['members', 'tasks'])))->resolve($request),

            // The shelves this project could sit on. Sent to every viewer, not
            // only leads: the read-only page still has to name the group the
            // project is in, and it only has the id.
            'groups' => ProjectGroupResource::collection(
                $workspace->projectGroups()->ordered()->get(),
            )->resolve($request),

            'columns' => BoardColumnResource::collection(
                $project->columns()->ordered()->get(),
            )->resolve($request),
            'members' => $this->boards->members($project),
            'labels' => $project->labels()->get()
                ->map(fn ($label): array => [
                    'id' => $label->id,
                    'name' => $label->name,
                    'color' => $label->color,
                ])
                ->all(),
            'canManage' => $request->user()->can('update', $project),

            // Who can still be added: everyone in the workspace who is not
            // already on the project. Deferred because the settings page opens
            // on the project's own details, not on a roster of the company.
            'addable' => Inertia::defer(fn (): array => $this->addableMembers($workspace, $project)),
        ]);
    }

    /**
     * Renaming a project, and everything else about what it *is*.
     *
     * Redirects to the project by its new slug rather than back: a rename
     * regenerates the slug — see {@see UpdateProject} — so `back()` would return
     * the person who just renamed it to a URL that no longer resolves.
     */
    public function update(
        Workspace $workspace,
        Project $project,
        UpdateProjectRequest $request,
        UpdateProject $updateProject,
    ): RedirectResponse {
        $updated = $updateProject->handle(
            project: $project,
            actor: $request->user(),
            attributes: $request->safe()->only([
                'name', 'key', 'description', 'color', 'uses_sprints', 'card_fields',
            ]),
        );

        return to_route('projects.settings.edit', [
            'workspace' => $workspace->slug,
            'project' => $updated->slug,
        ])->with('success', 'Project updated.');
    }

    /**
     * Moving a project onto a heading, or off every heading.
     *
     * Its own endpoint rather than a field on {@see update()} above, for the
     * reason that method redirects to the project's settings: this is done from
     * the project *list*, and answering a move with a trip to a settings page is
     * answering a question nobody asked. `back()` leaves the person where they
     * were, looking at the list they just rearranged.
     *
     * Gated on updating the project rather than on curating groups — moving
     * somebody else's project between shelves is a change to their project.
     */
    public function assignGroup(
        Workspace $workspace,
        Project $project,
        AssignProjectGroupRequest $request,
        UpdateProject $updateProject,
    ): RedirectResponse {
        $updateProject->handle(
            project: $project,
            actor: $request->user(),
            attributes: ['project_group_id' => $request->integer('project_group_id') ?: null],
        );

        return back()->with('success', "{$project->name} moved.");
    }

    /**
     * Archiving, and restoring.
     *
     * Two idempotent endpoints rather than one toggle: archiving is not an edit,
     * it closes the board, and a double-submitted "Archive" must not quietly
     * bring it back. The same pair {@see App\Http\Controllers\Channels\ChannelArchiveController}
     * offers, for the same reason.
     */
    public function archive(
        Workspace $workspace,
        Project $project,
        Request $request,
        SetProjectArchived $setArchived,
    ): RedirectResponse {
        $this->authorize('archive', $project);

        $setArchived->handle($project, $request->user(), archived: true);

        return to_route('projects.index', ['workspace' => $workspace->slug])
            ->with('success', "{$project->name} archived.");
    }

    public function unarchive(
        Workspace $workspace,
        Project $project,
        Request $request,
        SetProjectArchived $setArchived,
    ): RedirectResponse {
        $this->authorize('archive', $project);

        $setArchived->handle($project, $request->user(), archived: false);

        return back()->with('success', "{$project->name} restored.");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function addableMembers(Workspace $workspace, Project $project): array
    {
        $existing = $project->members()->pluck('user_id')->all();

        return $workspace->activeUsers()
            ->whereNotIn('users.id', $existing)
            ->orderBy('users.name')
            ->limit(200)
            ->get()
            ->map(fn ($user): array => (new UserResource($user))->resolve(request()))
            ->all();
    }
}
