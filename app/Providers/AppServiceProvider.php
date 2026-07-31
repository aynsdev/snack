<?php

namespace App\Providers;

use App\Models\Workspace;
use App\Notifications\Channels\WorkspaceDatabaseChannel;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One workspace context per request, populated by ResolveWorkspace.
        $this->app->scoped(WorkspaceContext::class);
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
        $this->configureDevCommands();
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
    }

    /**
     * Trim `composer run dev` to the processes this application needs.
     *
     * Herd already serves the site at snack.test, so the built-in PHP server is
     * a fifth process listening on a port nobody visits. Reverb and the queue
     * worker — both required for real time, since message events are queued
     * broadcasts — are registered by their own packages and stay.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::except('server');
    }

    /**
     * Rate limits for the endpoints a client can hit in a tight loop.
     *
     * Keyed by user id rather than IP: an office behind one NAT gateway is a
     * single IP, and a shared limit there would have colleagues throttling each
     * other out of their own chat. Every route these are mounted on sits behind
     * the auth middleware, so there is always a user to key by.
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
