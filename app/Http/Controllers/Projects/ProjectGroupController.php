<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Projects\MoveProjectGroup;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectGroupRequest;
use App\Http\Requests\Projects\UpdateProjectGroupRequest;
use App\Models\ProjectGroup;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The headings the project list is arranged under.
 *
 * A group has no page of its own — it is only ever a heading on
 * {@see ProjectController::index()} — so every action here answers with `back()`
 * and the list re-renders with the new arrangement.
 *
 * Which project sits under which heading is not decided here: that is a property
 * of the project, changed through {@see ProjectController::update()} by whoever
 * may edit the project. Curating the shelves and moving somebody's project
 * between them are deliberately different rights.
 */
class ProjectGroupController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function store(Workspace $workspace, StoreProjectGroupRequest $request): RedirectResponse
    {
        $group = ProjectGroup::create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'name' => $request->string('name')->trim()->toString(),
            'position' => ProjectGroup::nextPosition($workspace),
        ]);

        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::ProjectGroupCreated,
            actor: $request->user(),
            auditable: $group,
            newValues: ['name' => $group->name],
        );

        return back()->with('success', "{$group->name} added.");
    }

    public function update(
        Workspace $workspace,
        ProjectGroup $projectGroup,
        UpdateProjectGroupRequest $request,
    ): RedirectResponse {
        $before = $projectGroup->name;

        $projectGroup->update([
            'name' => $request->string('name')->trim()->toString(),
        ]);

        if ($projectGroup->wasChanged('name')) {
            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::ProjectGroupUpdated,
                actor: $request->user(),
                auditable: $projectGroup,
                oldValues: ['name' => $before],
                newValues: ['name' => $projectGroup->name],
            );
        }

        return back()->with('success', 'Group renamed.');
    }

    /**
     * One place up, or one place down.
     *
     * A dedicated endpoint rather than a `position` field on the update above:
     * the client says which way, and the server works out what that means
     * against the list as it is right now. A client that computed the number
     * itself would be computing it from a list that may have changed.
     */
    public function move(
        Workspace $workspace,
        ProjectGroup $projectGroup,
        Request $request,
        MoveProjectGroup $moveGroup,
    ): RedirectResponse {
        $this->authorize('update', $projectGroup);

        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:up,down'],
        ]);

        $moveGroup->handle($projectGroup, up: $validated['direction'] === 'up');

        return back();
    }

    /**
     * Removing a heading, not the projects under it.
     *
     * They become ungrouped and are still on the page — see the `nullOnDelete`
     * in the project_groups migration. That is why this needs no confirmation
     * beyond the one the client shows: nothing here is unrecoverable except the
     * name, which can be typed again.
     */
    public function destroy(
        Workspace $workspace,
        ProjectGroup $projectGroup,
        Request $request,
    ): RedirectResponse {
        $this->authorize('delete', $projectGroup);

        $name = $projectGroup->name;
        $ungrouped = $projectGroup->projects()->count();

        $projectGroup->delete();

        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::ProjectGroupDeleted,
            actor: $request->user(),
            auditable: $projectGroup,
            oldValues: ['name' => $name, 'projects' => $ungrouped],
        );

        return back()->with(
            'success',
            $ungrouped === 0
                ? "{$name} deleted."
                : "{$name} deleted. ".trans_choice(
                    '{1} One project is now ungrouped.|[2,*] :count projects are now ungrouped.',
                    $ungrouped,
                ),
        );
    }
}
