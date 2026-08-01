<?php

namespace App\Http\Middleware;

use App\Services\InstanceSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends visitors to first-run setup while the installation has no accounts.
 *
 * Applied only to the guest entry points, never globally: an authenticated
 * request is already proof that setup happened, so re-checking on every
 * workspace page would buy nothing and cost a query per request.
 */
class EnsureInstanceIsSetUp
{
    public function __construct(private readonly InstanceSetup $setup) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->setup->isPending()) {
            return redirect()->route('setup.create');
        }

        return $next($request);
    }
}
