<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Actions\Workspaces\CreateWorkspaceInviteLink;
use App\Actions\Workspaces\RevokeWorkspaceInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreInviteLinkRequest;
use App\Http\Resources\WorkspaceInvitationResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The workspace's one shareable invite link — the mirror of
 * {@see \App\Http\Controllers\Workspaces\InviteLinkController}.
 */
class InviteLinkController extends Controller
{
    /**
     * Create the link, or replace the live one with a new token. Answers with
     * its `url`, which the invitations list re-derives from the row on every
     * read, so nothing is lost if the app drops this response.
     */
    public function store(
        Workspace $workspace,
        StoreInviteLinkRequest $request,
        CreateWorkspaceInviteLink $createLink,
    ): JsonResponse {
        $link = $createLink->handle(
            workspace: $workspace,
            creator: $request->user(),
            role: $request->role(),
            maxUses: $request->maxUses(),
        );

        return (new WorkspaceInvitationResource($link->load('inviter')))
            ->withUrl()
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Turn the link off. Idempotent: no live link is already the state asked
     * for, so it is a 204 too.
     */
    public function destroy(
        Workspace $workspace,
        Request $request,
        RevokeWorkspaceInvitation $revokeInvitation,
    ): Response {
        $link = $workspace->invitations()->links()->outstanding()->first();

        if ($link !== null) {
            $this->authorize('revoke', $link);

            $revokeInvitation->handle($link, $request->user());
        }

        return response()->noContent();
    }
}
