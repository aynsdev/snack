<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything addressed to the viewer in this workspace, newest first — the
 * same {@see ActivityFeed} the web Mentions page and Activity tab read.
 */
class MentionController extends Controller
{
    public function __construct(private readonly ActivityFeed $feed) {}

    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->feed->mentions($workspace, $request->user(), $request),
        ]);
    }
}
