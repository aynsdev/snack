<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps one person's page out of everybody else's browser.
 *
 * # What this is defending
 *
 * Every HTML document this application serves has the signed-in person inside
 * it. Inertia embeds the whole shared payload — `auth.user`, the workspace rail,
 * the sidebar — in the `data-page` attribute of the root element, and the
 * document also carries the CSRF token bound to that session. A cache that
 * stores one such document and hands it to the next visitor puts them on
 * somebody else's account, and no amount of correct authorization on the server
 * prevents it: the request never reaches the server at all.
 *
 * Laravel already marks these responses `private, no-cache`, which a correct
 * intermediary honours. This adds the two things that survive an *incorrect*
 * one:
 *
 *  - `no-store`, which says the response may not be written down at all, rather
 *    than `no-cache`'s weaker "written down, but revalidate before reuse". A
 *    proxy configured to cache aggressively — a CDN "cache everything" rule, an
 *    nginx `proxy_cache` with its own TTL — commonly overrides `private` and
 *    `no-cache`, and far more rarely overrides `no-store`.
 *
 *  - `Vary: Cookie`, so that a cache which stores it anyway is at least keyed by
 *    session rather than serving one entry to every visitor. Responses already
 *    vary on `X-Inertia`; the session cookie is the axis that actually decides
 *    who the document is about, and it was missing.
 *
 * # What it deliberately leaves alone
 *
 * A response that has asked for a lifetime of its own — an attachment download,
 * see {@see App\Http\Controllers\Files\DownloadController} — keeps it. Those are
 * per-viewer authorized and already marked private, and their caching is what
 * stops a timeline of images re-fetching every time it is opened. Overriding
 * them here would trade a real performance loss for no safety gain.
 *
 * # Cost
 *
 * `no-store` opts the document out of Chrome's back/forward cache. For an
 * Inertia application that is close to free: navigation inside the app is
 * client-side and never reloads the document, so this only affects returning
 * from an external page, which re-renders instead of restoring.
 */
class PreventSharedCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->setsItsOwnLifetime($response)) {
            return $response;
        }

        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, private, must-revalidate, max-age=0',
        );

        // Appended rather than set: Inertia varies on X-Inertia, and replacing
        // that would let a cache serve a page response to a partial-visit
        // request, which expects JSON.
        $response->headers->set('Vary', 'Cookie', replace: false);

        return $response;
    }

    /**
     * Whether the response has deliberately asked to be cached for a while.
     *
     * Read from the header rather than from Symfony's cache API, which reports
     * a computed lifetime for responses that never asked for one.
     */
    private function setsItsOwnLifetime(Response $response): bool
    {
        $control = $response->headers->get('Cache-Control', '');

        return str_contains($control, 'max-age=')
            && ! str_contains($control, 'max-age=0');
    }
}
