<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\ThreadHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "Threads" view: every thread the viewer follows, newest activity first.
 *
 * A single thread's contents, its read pointer and following it are the web
 * app's JSON endpoints, routed to directly.
 */
class ThreadController extends Controller
{
    public function __construct(private readonly ThreadHistory $history) {}

    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->history->followed($workspace, $request->user(), $request),
        ]);
    }
}
