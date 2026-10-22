<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContainerSummary;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\MessageSearch;
use App\Support\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workspace message search — the mirror of {@see \App\Http\Controllers\SearchController}.
 *
 * `?q=` takes the same syntax as the web box: words, plus `from:@user`,
 * `in:#channel`, `has:file`, `before:YYYY-MM-DD` and `after:YYYY-MM-DD`. Ranked
 * results are paged by number (`?page=`), since rank cannot be a cursor.
 */
class SearchController extends Controller
{
    public function __construct(private readonly MessageSearch $search) {}

    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $raw = $request->string('q')->toString();
        $query = SearchQuery::parse($raw);

        $found = $this->search->search(
            workspace: $workspace,
            user: $request->user(),
            query: $query,
            page: $request->integer('page') ?: 1,
        );

        return response()->json([
            'data' => [
                'query' => [
                    'raw' => $raw,
                    'terms' => $query->terms,
                    'from' => $query->from,
                    'in' => $query->in,
                    'has_file' => $query->requiresFile,
                    'before' => $query->before?->toDateString(),
                    'after' => $query->after?->toDateString(),
                    'is_empty' => $query->isEmpty(),
                    'too_short' => $raw !== '' && $query->isEmpty(),
                ],
                'results' => $found['results']
                    ->map(fn (Message $message): array => [
                        'message' => (new MessageResource($message))->resolve($request),
                        'container' => ContainerSummary::for($message),
                        // Structured highlight segments, never markup.
                        'snippet' => $this->search->segments($message->getAttribute('search_snippet')),
                    ])
                    ->values()
                    ->all(),
                'total' => $found['total'],
                'page' => $found['page'],
                'has_more' => $found['has_more'],
            ],
        ]);
    }
}
