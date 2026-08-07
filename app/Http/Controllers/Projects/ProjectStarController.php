<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Keeping a project at the top of your own sidebar.
 *
 * Its own endpoint pair rather than a field on the project, because a star is
 * not a fact about the project at all — it is a fact about the person looking
 * at it, and two people on the same board disagree about it all day. The same
 * shape as archiving a channel: store to set, destroy to clear, `back()` to
 * leave the reader where they were.
 *
 * Gated on membership rather than on managing the project. A star is the
 * lightest thing anybody can do here, and a member who cannot rearrange their
 * own sidebar has been refused something that affects nobody else.
 */
class ProjectStarController extends Controller
{
    public function store(Workspace $workspace, Project $project, Request $request): RedirectResponse
    {
        $this->authorize('star', $project);

        // Idempotent: a double-tapped star must not move the date it recorded,
        // which is what the starred list is ordered by.
        $membership = $project->membershipFor($request->user());

        if ($membership !== null && $membership->starred_at === null) {
            $membership->forceFill(['starred_at' => now()])->save();
        }

        return back();
    }

    public function destroy(Workspace $workspace, Project $project, Request $request): RedirectResponse
    {
        $this->authorize('star', $project);

        $project->membershipFor($request->user())
            ?->forceFill(['starred_at' => null])
            ->save();

        return back();
    }
}
