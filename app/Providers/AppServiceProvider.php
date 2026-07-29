<?php

namespace App\Providers;

use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
