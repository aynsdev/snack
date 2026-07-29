<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Http\Middleware\ResolveWorkspace;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use RuntimeException;

/**
 * The workspace the current request is acting within.
 *
 * Resolved once by {@see ResolveWorkspace} and injected
 * wherever it is needed, so controllers, actions, and Inertia serializers all
 * agree on the tenant without re-reading the route or re-querying membership.
 *
 * Registered as a scoped binding: one instance per request, never shared across
 * requests or queue jobs.
 */
class WorkspaceContext
{
    private ?Workspace $workspace = null;

    private ?WorkspaceMember $membership = null;

    /**
     * Bind the workspace for this request. Called only by the middleware.
     */
    public function set(Workspace $workspace, WorkspaceMember $membership): void
    {
        $this->workspace = $workspace;
        $this->membership = $membership;
    }

    public function isResolved(): bool
    {
        return $this->workspace !== null;
    }

    /**
     * @throws RuntimeException when the current route is not workspace-scoped.
     */
    public function workspace(): Workspace
    {
        return $this->workspace ?? throw new RuntimeException(
            'No workspace is bound to this request. Did the route miss the "workspace" middleware?',
        );
    }

    /**
     * @throws RuntimeException when the current route is not workspace-scoped.
     */
    public function membership(): WorkspaceMember
    {
        return $this->membership ?? throw new RuntimeException(
            'No workspace membership is bound to this request. Did the route miss the "workspace" middleware?',
        );
    }

    /**
     * The acting user's role in the current workspace.
     */
    public function role(): WorkspaceRole
    {
        return $this->membership()->role;
    }

    public function workspaceOrNull(): ?Workspace
    {
        return $this->workspace;
    }

    public function membershipOrNull(): ?WorkspaceMember
    {
        return $this->membership;
    }
}
