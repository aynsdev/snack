<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Actions\Workspaces\InviteWorkspaceMember;
use App\Actions\Workspaces\RevokeWorkspaceInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreWorkspaceInvitationRequest;
use App\Http\Resources\WorkspaceInvitationResource;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Admin-side invitation management — the mirror of
 * {@see \App\Http\Controllers\Workspaces\InvitationController}. Redeeming one
 * is {@see \App\Http\Controllers\Api\V1\InvitationController}.
 */
class InvitationController extends Controller
{
    /**
     * The emailed invitations still outstanding (expired ones included, so an
     * admin sees who never arrived), paged by `?page=`, plus the shareable
     * link as `invite_link` — null when there is none.
     */
    public function index(Workspace $workspace, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [WorkspaceInvitation::class, $workspace]);

        $invitations = $workspace->invitations()
            ->addressed()
            ->outstanding()
            ->with('inviter')
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $link = $workspace->invitations()
            ->links()
            ->outstanding()
            ->with('inviter')
            ->latest()
            ->first();

        return WorkspaceInvitationResource::collection($invitations)->additional([
            'invite_link' => $link === null
                ? null
                : (new WorkspaceInvitationResource($link))->withUrl()->resolve($request),
        ]);
    }

    /**
     * The link itself goes to the recipient by email, never back to the
     * inviter — the same as on the web.
     */
    public function store(
        Workspace $workspace,
        StoreWorkspaceInvitationRequest $request,
        InviteWorkspaceMember $inviteMember,
    ): JsonResponse {
        $invitation = $inviteMember->handle(
            workspace: $workspace,
            inviter: $request->user(),
            email: $request->string('email')->toString(),
            role: $request->role(),
        );

        return (new WorkspaceInvitationResource($invitation->load('inviter')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(
        Workspace $workspace,
        WorkspaceInvitation $invitation,
        Request $request,
        RevokeWorkspaceInvitation $revokeInvitation,
    ): Response {
        $this->authorize('revoke', $invitation);

        $revokeInvitation->handle($invitation, $request->user());

        return response()->noContent();
    }
}
