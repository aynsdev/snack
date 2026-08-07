<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSharedCaching;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',

        /*
         * Incoming webhooks, registered outside the `web` group on purpose —
         * see routes/webhooks.php. A machine posting from a deploy script has no
         * session and no CSRF token, and giving it one of each would reject
         * every delivery and mint a session for nobody.
         */
        then: function (): void {
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            /*
             * First in the list, which makes it *last* on the way out: a
             * response unwinds the stack in reverse, and Inertia's middleware
             * replaces the Vary header wholesale. Registered after it, this one
             * had its Vary silently overwritten — see PreventSharedCaching, and
             * the test that pins the order.
             */
            PreventSharedCaching::class,

            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Applied to every /w/{workspace} route; see ResolveWorkspace for why a
        // non-member receives a 404 rather than a 403.
        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            /*
             * `hooks/*` is here because its callers are machines that mostly do
             * not send an Accept header — curl sends a bare wildcard — so
             * `expectsJson()` says no and a validation failure would answer with
             * a 302 to a login page. The path is matched rather than the header
             * for exactly that reason: what makes this endpoint JSON is what it
             * is, not what the caller remembered to ask for.
             */
            fn (Request $request) => $request->is('api/*')
                || $request->is('hooks/*')
                || $request->expectsJson(),
        );
    })->create();
