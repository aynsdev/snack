<?php

namespace App\Http\Middleware;

use App\Services\InstanceSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes first-run setup permanently once an account exists.
 *
 * This is the security boundary for the whole feature. Setup creates a
 * workspace owner without an invitation and signs them straight in, so leaving
 * it reachable on a live installation would hand ownership of a new workspace
 * to any anonymous visitor. A 404 rather than a 403 keeps a set-up instance
 * from advertising that the route was ever there.
 */
class EnsureInstanceNeedsSetup
{
    public function __construct(private readonly InstanceSetup $setup) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->setup->isComplete(), 404);

        return $next($request);
    }
}
