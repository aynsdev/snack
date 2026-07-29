<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces workspace isolation on every workspace-scoped route.
 *
 * This is the single choke point where "is this person allowed inside this
 * tenant at all?" is answered. Controllers and policies below it can assume a
 * resolved, active membership.
 *
 * A non-member gets a 404 rather than a 403: a 403 would confirm that a
 * workspace with that slug exists, which is enough to enumerate customers once
 * Snack is multi-tenant.
 */
class ResolveWorkspace
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        $user = $request->user();

        if (! $workspace instanceof Workspace || $user === null) {
            abort(404);
        }

        // Load every membership the user holds, not just this one. Policies ask
        // `roleIn()` repeatedly while rendering the sidebar, and the loaded
        // relation turns all of those into in-memory lookups.
        $user->loadMissing('workspaceMemberships');

        $membership = $user->membershipIn($workspace);

        // Suspended members are treated exactly like non-members: their history
        // is preserved, but every door is closed.
        if ($membership === null || ! $membership->isActive()) {
            abort(404);
        }

        $this->context->set($workspace, $membership);

        // Remember where the user was so a bare "/" can send them back to the
        // workspace they were last using.
        $request->session()->put('snack.current_workspace', $workspace->slug);

        return $next($request);
    }
}
