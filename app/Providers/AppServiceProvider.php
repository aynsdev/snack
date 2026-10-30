<?php

namespace App\Providers;

use App\Actions\Presence\RecordPresence;
use App\Http\Middleware\EnsureMobileToken;
use App\Mcp\OAuthClientRepository;
use App\Models\OAuthToken;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\WorkspaceDatabaseChannel;
use App\Services\ActorContext;
use App\Services\Outbound\OutboundEvents;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One workspace context per request, populated by ResolveWorkspace.
        $this->app->scoped(WorkspaceContext::class);

        // Who is acting and how, per request — see ActorContext.
        $this->app->scoped(ActorContext::class);

        // One outbox buffer per request or job, shared by every service that
        // reports to the agent dispatcher — see OutboundEvents::transaction().
        $this->app->scoped(OutboundEvents::class);

        // A client id that is not a UUID is "no such client", not a 500 —
        // see OAuthClientRepository.
        $this->app->bind(ClientRepository::class, OAuthClientRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRouteBindings();
        $this->configureRateLimiters();
        $this->configureNotifications();
        $this->configurePresence();
        $this->configureDevCommands();
        $this->configurePassport();
    }

    /**
     * Passport, which exists here for one reason: letting AI clients reach
     * the MCP server. See routes/ai.php.
     *
     * The consent screen is Snack's own rather than Passport's, because it
     * asks a question Passport does not know about — which workspace the
     * assistant should work in. See App\Http\Controllers\Mcp\AuthorizeWorkspaceController.
     *
     * OAuth access tokens are short-lived and refreshed by the client, so a
     * disconnected assistant is locked out within the hour even by a path that
     * forgot to revoke. Personal tokens from the connectors page keep
     * Passport's year: they are pasted into config files by hand, and a
     * credential that silently expires monthly is one people stop trusting.
     */
    protected function configurePassport(): void
    {
        Passport::useTokenModel(OAuthToken::class);

        Passport::authorizationView(fn (array $parameters) => response()->view('mcp.authorize', [
            ...$parameters,
            // Active memberships only — see User::workspaces().
            'workspaces' => $parameters['user']->workspaces()
                ->orderBy('name')
                ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug']),
        ]));

        /*
         * The scope the mobile app's tokens carry — see EnsureMobileToken.
         * Merged rather than set, because the MCP package registers its own
         * `mcp:use` scope the same way.
         */
        Passport::tokensCan([
            ...Passport::$scopes,
            EnsureMobileToken::SCOPE => 'Use the Snack mobile app',
        ]);

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(90));
    }

    /**
     * Withdraw somebody's presence the moment they sign out.
     *
     * The scheduled sweep would get there eventually, but "eventually" is up to
     * five minutes of a green dot next to somebody who has demonstrably left,
     * and signing out is the one departure the application is actually told
     * about. Taking it immediately is free.
     *
     * Signing *in* is deliberately not handled here: the client's first
     * heartbeat fires as the workspace mounts, a moment later, and one writer
     * for "this person is here" is easier to reason about than two.
     */
    protected function configurePresence(): void
    {
        Event::listen(function (Logout $event) {
            if ($event->user instanceof User) {
                app(RecordPresence::class)->offline($event->user);
            }
        });
    }

    /**
     * Teach the database notification channel about the tenant.
     *
     * Snack's `notifications` table carries a `workspace_id` that the framework's
     * channel knows nothing about, and the unread badge's partial index is keyed
     * on it. Replacing the driver here means every `via() => ['database']` fills
     * the column in — see {@see WorkspaceDatabaseChannel} — rather than each
     * notification having to remember to.
     */
    protected function configureNotifications(): void
    {
        /*
         * The container arrives as the argument rather than being read from
         * `$this`: Manager invokes a custom creator against itself, so `$this`
         * inside the closure is the ChannelManager, not this provider.
         */
        Notification::extend(
            'database',
            fn (Application $app): WorkspaceDatabaseChannel => $app->make(WorkspaceDatabaseChannel::class),
        );

        // Mobile push — see PushChannel. Named so `via()` can say `push`.
        Notification::extend(
            'push',
            fn (Application $app): PushChannel => $app->make(PushChannel::class),
        );
    }

    /**
     * Trim `composer run dev` to the processes this application needs.
     *
     * Herd already serves the site at snack.test, so the built-in PHP server is
     * a fifth process listening on a port nobody visits. Reverb and the queue
     * worker — both required for real time, since message events are queued
     * broadcasts — are registered by their own packages and stay.
     *
     * A second worker joins the first. With one, everything queues behind
     * whatever Bite is doing: a reply that takes twenty seconds of model calls
     * holds up every message broadcast, and the image Bite queued halfway
     * through cannot even be submitted until the reply it is part of is done.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::except('server');

        // The default worker plus the agent dispatcher's own queue — see
        // `snack.agents.queue`. Without it, outbound events never leave in dev.
        DevCommands::artisan('queue:listen --queue=default,'.config('snack.agents.queue').' --tries=1 --timeout=0', 'queue');
        DevCommands::artisan('queue:listen --tries=1 --timeout=0', 'queue-2');
    }

    /**
     * Rate limits for the endpoints a client can hit in a tight loop.
     *
     * Keyed by user id rather than IP: an office behind one NAT gateway is a
     * single IP, and a shared limit there would have colleagues throttling each
     * other out of their own chat. Every route these are mounted on sits behind
     * the auth middleware, so there is always a user to key by — with the one
     * exception noted on `webhooks` below.
     */
    protected function configureRateLimiters(): void
    {
        /*
         * Sending. Set well above human typing speed — the ceiling exists to
         * stop a runaway script or a retry loop from flooding a channel, not to
         * pace a fast conversation.
         */
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(120)
            ->by((string) $request->user()->id));

        /*
         * Reading. Scrolling back through history and syncing read state are
         * both cheap and legitimately bursty, so this is deliberately generous.
         */
        RateLimiter::for('message-reads', fn (Request $request) => Limit::perMinute(300)
            ->by((string) $request->user()->id));

        /*
         * Uploading. Much tighter than the others because each request costs
         * disk rather than a row: at the default 50 MB ceiling this still allows
         * three gigabytes a minute from one account, which is far more than
         * anyone attaches to a message and far less than a script could.
         */
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->user()->id));

        /*
         * Joining huddles. Far tighter than messages, because each request
         * mints a media credential and opens a WebRTC connection rather than
         * writing a row — and because nobody legitimately joins a call more than
         * a handful of times a minute. Loose enough to absorb a flaky connection
         * reconnecting repeatedly, which is exactly when a person can least
         * afford to be locked out of the call they are trying to rejoin.
         */
        RateLimiter::for('huddles', fn (Request $request) => Limit::perMinute(20)
            ->by((string) $request->user()->id));

        /*
         * Heartbeats. One tab beats once a minute, but people legitimately keep
         * Snack open in several at once and every one of them beats, plus a beat
         * each time a tab is focused. Generous enough that a wall of tabs is
         * fine, low enough that this cannot be used to hammer a write per
         * request.
         */
        RateLimiter::for('presence', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->user()->id));

        /*
         * Incoming webhook deliveries — the one limiter with no user to key by.
         *
         * Keyed by the token instead, so the limit belongs to the integration
         * rather than to whoever it happens to be running on: a dozen hooks
         * behind one CI provider share an address, and an IP key would let one
         * team's retry loop silence every other team's notifications.
         *
         * The token is hashed into the key rather than used raw. Throttle keys
         * end up in the cache store, in Redis' keyspace, and in anything that
         * dumps slow commands — none of which are places a live credential
         * should be sitting in plaintext.
         *
         * A miss falls back to the IP so a request with no token at all still
         * cannot be replayed without limit.
         */
        RateLimiter::for('webhooks', function (Request $request) {
            $token = $request->route('token');

            return Limit::perMinute((int) config('snack.webhooks.rate_limit_per_minute'))
                ->by(is_string($token) ? 'hook:'.hash('sha256', $token) : 'hook-ip:'.$request->ip());
        });

        /*
         * The MCP server. An assistant working through a question makes a burst
         * of tool calls in a few seconds, so this sits above the message-read
         * limit — but it is still one person's credential, and a client stuck in
         * a retry loop should hit a ceiling rather than the database.
         */
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute((int) config('snack.mcp.rate_limit_per_minute', 120))
            ->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Resolve {workspace} explicitly rather than relying on implicit binding.
     *
     * Laravel's implicit binding only substitutes route parameters that appear
     * in the controller method's signature. Most workspace-scoped controllers
     * never type-hint the workspace — they read it from
     * {@see WorkspaceContext} — so {workspace} would arrive at the
     * ResolveWorkspace middleware as a raw slug and be rejected as invalid.
     *
     * An explicit binder runs for every route that has the parameter,
     * regardless of the controller's signature.
     */
    protected function configureRouteBindings(): void
    {
        Route::bind('workspace', function (string $slug): Workspace {
            return Workspace::where('slug', $slug)->firstOrFail();
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
