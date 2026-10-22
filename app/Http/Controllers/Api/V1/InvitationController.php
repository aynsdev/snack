<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workspaces\AcceptWorkspaceInvitation;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Redeeming an invitation from the app.
 *
 * Looking one up needs no account — the token is the credential, and someone
 * who was invited needs to see what to before signing up. Like the web landing
 * page it reveals only the workspace and who sent it.
 */
class InvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return response()->json([
                'message' => 'This invitation can no longer be used.',
                'reason' => $invitation?->status() ?? 'invalid',
            ], 404);
        }

        $invitation->load(['workspace', 'inviter']);

        return response()->json([
            'data' => [
                'workspace' => [
                    'name' => $invitation->workspace->name,
                    'slug' => $invitation->workspace->slug,
                    'initials' => $invitation->workspace->initials(),
                    'logo_url' => $invitation->workspace->logoUrl(),
                    'description' => $invitation->workspace->description,
                ],
                'invited_by' => $invitation->inviter->name,
                'email' => $invitation->email,
                'is_link' => $invitation->isLink(),
                'role' => $invitation->role->label(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function store(
        string $token,
        Request $request,
        AcceptWorkspaceInvitation $acceptInvitation,
    ): WorkspaceResource {
        $invitation = WorkspaceInvitation::findByToken($token);

        abort_if($invitation === null, 404);

        $this->authorize('accept', $invitation);

        $membership = $acceptInvitation->handle($invitation, $request->user());

        return new WorkspaceResource($membership->workspace);
    }
}
