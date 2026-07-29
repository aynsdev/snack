<?php

namespace App\Providers;

use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        $this->configureDevCommands();
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
