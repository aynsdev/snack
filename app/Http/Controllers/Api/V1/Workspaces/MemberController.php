<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Actions\Workspaces\RemoveWorkspaceMember;
use App\Actions\Workspaces\SetWorkspaceMemberStatus;
use App\Actions\Workspaces\UpdateWorkspaceMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateWorkspaceMemberRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The member directory and its administration — the mirror of
 * {@see \App\Http\Controllers\Workspaces\MemberController}.
 */
class MemberController extends Controller
{
    /**
     * Paged by number, fifty at a time and ordered by name (`?page=`).
     * `can_manage` says whether the viewer may change roles and invite.
     */
    public function index(Workspace $workspace, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewMembers', $workspace);

        $members = $workspace->members()
            ->with('user')
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->orderBy('users.name')
            ->select('workspace_members.*')
            ->paginate(50)
            ->withQueryString();

        return WorkspaceMemberResource::collection($members)->additional([
            'can_manage' => $request->user()->can('inviteMembers', $workspace),
        ]);
    }

    /**
     * Change a member's role, suspend them, or reinstate them. Authorized by
     * the request, on WorkspacePolicy::manageMember.
     */
    public function update(
        Workspace $workspace,
        WorkspaceMember $member,
        UpdateWorkspaceMemberRequest $request,
        UpdateWorkspaceMemberRole $updateRole,
        SetWorkspaceMemberStatus $setStatus,
    ): WorkspaceMemberResource {
        $actor = $request->user();

        if (($role = $request->newRole()) !== null) {
            $updateRole->handle($member, $role, $actor);
        }

        if (($status = $request->newStatus()) !== null) {
            $setStatus->handle($member, $status, $actor);
        }

        return new WorkspaceMemberResource($member->refresh()->load('user'));
    }

    public function destroy(
        Workspace $workspace,
        WorkspaceMember $member,
        Request $request,
        RemoveWorkspaceMember $removeMember,
    ): Response {
        $this->authorize('manageMember', [$workspace, $member]);

        $removeMember->handle($member, $request->user());

        return response()->noContent();
    }
}
