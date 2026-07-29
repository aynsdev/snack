<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspaces\RemoveWorkspaceMember;
use App\Actions\Workspaces\SetWorkspaceMemberStatus;
use App\Actions\Workspaces\UpdateWorkspaceMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateWorkspaceMemberRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewMembers', $workspace);

        // Joined to `users` so the directory can be ordered by person's name
        // rather than by membership id, without loading the whole table.
        $members = $workspace->members()
            ->with('user')
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->orderBy('users.name')
            ->select('workspace_members.*')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('workspace/members', [
            'members' => WorkspaceMemberResource::collection($members),
            'canManage' => $request->user()->can('inviteMembers', $workspace),
        ]);
    }

    /**
     * Change a member's role, suspend them, or reinstate them.
     *
     * Authorization lives in UpdateWorkspaceMemberRequest::authorize(), which
     * consults WorkspacePolicy::manageMember — the rule that stops an admin
     * from acting on an owner, a peer admin, or themselves.
     */
    public function update(
        Workspace $workspace,
        WorkspaceMember $member,
        UpdateWorkspaceMemberRequest $request,
        UpdateWorkspaceMemberRole $updateRole,
        SetWorkspaceMemberStatus $setStatus,
    ): RedirectResponse {
        $actor = $request->user();

        if (($role = $request->newRole()) !== null) {
            $updateRole->handle($member, $role, $actor);
        }

        if (($status = $request->newStatus()) !== null) {
            $setStatus->handle($member, $status, $actor);
        }

        return back()->with('success', 'Member updated.');
    }

    public function destroy(
        Workspace $workspace,
        WorkspaceMember $member,
        Request $request,
        RemoveWorkspaceMember $removeMember,
    ): RedirectResponse {
        $this->authorize('manageMember', [$workspace, $member]);

        $removeMember->handle($member, $request->user());

        return back()->with('success', 'Member removed from the workspace.');
    }
}
