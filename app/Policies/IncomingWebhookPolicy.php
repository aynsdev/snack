<?php

namespace App\Policies;

use App\Models\IncomingWebhook;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Incoming webhook authorization.
 *
 * Every right here is a workspace-admin right, including *reading* the list —
 * which is unusual enough in Snack to be worth saying why.
 *
 * A hook's URL is a credential that posts into a channel forever, and the admin
 * screen shows it in plaintext because there is nowhere else it could come
 * from. So "who may look at this page" is the same question as "who may take a
 * permanent write capability out of the building", and it sits with the people
 * who already decide what may reach the workspace's members — alongside
 * invitations, settings, and standups.
 *
 * That is the one distinction from {@see StandupPolicy}, where viewing is open
 * to admins for a *presentational* reason. Here it is the security boundary.
 *
 * Note there is no policy method for a delivery. A delivery has no user to
 * authorize: the token is the whole credential and the channel is a column, so
 * what would be a policy check everywhere else in Snack is a lookup here. See
 * {@see App\Http\Controllers\Webhooks\WebhookDeliveryController}.
 */
class IncomingWebhookPolicy
{
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $this->administers($user, $workspace);
    }

    public function create(User $user, Workspace $workspace): Response
    {
        return $this->administers($user, $workspace);
    }

    public function update(User $user, IncomingWebhook $webhook): Response
    {
        return $this->administers($user, $webhook->workspace);
    }

    /**
     * Turning a hook off.
     *
     * The same rank as creating one, deliberately, and not softened for the
     * revoker: shutting off a hook that is misbehaving is the emergency action,
     * and an admin should never have to think about whether they are allowed.
     */
    public function revoke(User $user, IncomingWebhook $webhook): Response
    {
        return $this->administers($user, $webhook->workspace);
    }

    public function delete(User $user, IncomingWebhook $webhook): Response
    {
        return $this->administers($user, $webhook->workspace);
    }

    private function administers(User $user, Workspace $workspace): Response
    {
        $role = $user->roleIn($workspace);

        if ($role === null) {
            // Not found rather than forbidden, matching every other
            // workspace-scoped policy: a non-member learns nothing about which
            // workspaces exist. See ResolveWorkspace.
            return Response::denyAsNotFound();
        }

        return $role->canAdministerWorkspace()
            ? Response::allow()
            : Response::deny('Only workspace admins can manage webhooks.');
    }
}
