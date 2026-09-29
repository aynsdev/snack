<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the OAuth consent screen a real page, never an Inertia visit.
 *
 * Passport's consent screen is plain Blade, not an Inertia page. That is fine
 * when an AI client opens it directly — but a person who was not signed in is
 * sent to Snack's login first, and the login form is Inertia. After signing in,
 * Fortify redirects to the intended URL, `oauth/authorize`, and Inertia follows
 * that redirect over XHR, receives HTML it cannot render, and shows it in its
 * error dialog: a sandboxed iframe where the Connect button's form is blocked.
 * The person is stuck on a screen that looks right and does nothing.
 *
 * So any Inertia request for an `oauth/*` page is answered with a 409 and
 * `X-Inertia-Location`, which tells the client to load the URL as a full page.
 */
class LeaveInertiaForOAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->header('X-Inertia') && $request->is('oauth/*')) {
            return Inertia::location($request->fullUrl());
        }

        return $next($request);
    }
}
