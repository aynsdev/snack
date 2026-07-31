<?php

namespace App\Http\Middleware;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceNavigator;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly WorkspaceNavigator $navigator,
    ) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Anything that depends on route-scoped state is shared as a Closure.
     * Inertia invokes `share()` on the way *into* the pipeline — before route
     * middleware such as ResolveWorkspace has run — and only evaluates the
     * closures when the response is rendered, by which time the workspace has
     * been resolved. Sharing eager values here would always yield a null
     * workspace.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => fn (): array => [
                'user' => $request->user(),
                // The richer, workspace-facing shape used by the sidebar,
                // avatars, and presence. `auth.user` is left exactly as the
                // starter kit had it so the settings pages keep working.
                'profile' => $request->user() !== null
                    ? (new UserResource($request->user()))->resolve($request)
                    : null,
            ],
            'snack' => fn (): array => $this->snackProps($request),
            'flash' => fn (): array => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Workspace navigation shared with every page.
     *
     * The rail is present on any authenticated page so the workspace switcher
     * always works. The heavier sidebar payload is built only inside a resolved
     * workspace, so auth and settings pages pay none of its query cost.
     *
     * @return array<string, mixed>
     */
    private function snackProps(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['workspaces' => [], 'current' => null];
        }

        $workspace = $this->context->isResolved()
            ? $this->context->workspace()
            : $this->lastVisitedWorkspace($request, $user);

        return [
            'workspaces' => $this->navigator->rail($user),
            'current' => $workspace === null
                ? null
                : $this->navigator->payload($workspace, $user),
        ];
    }

    /**
     * The workspace to dress a page that is not itself workspace-scoped.
     *
     * Settings live at `/settings/*`, outside the `/w/{workspace}` prefix, so
     * ResolveWorkspace never runs for them and nothing binds a tenant. Without a
     * fallback the shell renders with no rail and no sidebar, and walking into
     * settings feels like being thrown out of the workspace.
     *
     * ResolveWorkspace already records where the user last was, so that is what
     * dresses these pages.
     *
     * # The session value is not trusted
     *
     * It is re-resolved and the membership re-checked on every request. A
     * session outlives being removed from a workspace — or having the
     * membership suspended — and honouring a stale slug would rebuild that
     * workspace's channel list and unread counts for somebody who is no longer
     * in it. This is the same rule the rest of Snack applies to any identifier
     * that did not come from the current request's authorization.
     */
    private function lastVisitedWorkspace(Request $request, User $user): ?Workspace
    {
        $slug = $request->session()->get('snack.current_workspace');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $workspace = Workspace::query()->where('slug', $slug)->first();

        if ($workspace === null) {
            return null;
        }

        $membership = $user->membershipIn($workspace);

        return $membership?->isActive() === true ? $workspace : null;
    }
}
