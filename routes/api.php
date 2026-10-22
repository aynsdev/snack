<?php

use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Middleware\EnsureMobileToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API
|--------------------------------------------------------------------------
|
| Everything the web app does, for the mobile app, under /api/v1. Sessionless:
| the app signs in at `auth/token` for a Passport bearer token carrying the
| `mobile` scope, and EnsureMobileToken turns every other kind of token away —
| an MCP connector's included.
|
| Workspace routes mirror the web app's /w/{workspace} tree and sit behind the
| same `workspace` middleware, so tenancy and membership are decided exactly
| where they are for the browser. Each feature area keeps its own file under
| routes/api/v1.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/token', [TokenController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.token.store');

    require __DIR__.'/api/v1/guest.php';

    Route::middleware(['auth:api', EnsureMobileToken::class])->group(function () {
        Route::delete('auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');

        require __DIR__.'/api/v1/account.php';

        Route::middleware('verified')->group(function () {
            require __DIR__.'/api/v1/workspaces.php';
            require __DIR__.'/api/v1/files.php';

            Route::middleware('workspace')
                ->prefix('w/{workspace}')
                ->scopeBindings()
                ->group(function () {
                    foreach (glob(__DIR__.'/api/v1/workspace/*.php') as $file) {
                        require $file;
                    }
                });
        });
    });
});
