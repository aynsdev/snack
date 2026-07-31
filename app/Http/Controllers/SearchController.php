<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContainerSummary;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\MessageSearch;
use App\Support\SearchQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Workspace search.
 *
 * An Inertia page rather than a JSON endpoint, unlike the message timeline: a
 * result set is server-owned and replaced wholesale on each new query — there is
 * nothing live about it, nothing arrives over the WebSocket, and the URL *should*
 * be the state so a search can be linked, bookmarked, and reloaded.
 *
 * Ranked results are paged by number rather than by cursor. Everywhere else in
 * Snack pages by cursor because rows arrive mid-scroll and an OFFSET would skip
 * or repeat them — but a cursor has to be a stable monotonic column, and rank is
 * neither. A result page is a snapshot of a query, so an offset is the honest
 * shape for it.
 */
class SearchController extends Controller
{
    public function __construct(private readonly MessageSearch $search) {}

    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $raw = $request->string('q')->toString();
        $query = SearchQuery::parse($raw);

        $found = $this->search->search(
            workspace: $workspace,
            user: $request->user(),
            query: $query,
            page: $request->integer('page') ?: 1,
        );

        return Inertia::render('search/index', [
            'query' => [
                'raw' => $raw,
                'terms' => $query->terms,
                'from' => $query->from,
                'in' => $query->in,
                'has_file' => $query->requiresFile,
                'before' => $query->before?->toDateString(),
                'after' => $query->after?->toDateString(),
                /*
                 * Told apart so the empty state can say the right thing: "type
                 * something" and "nothing matched" are different messages, and
                 * "your search was too short to run" is a third.
                 */
                'is_empty' => $query->isEmpty(),
                'too_short' => $raw !== '' && $query->isEmpty(),
            ],

            'results' => $found['results']
                ->map(fn (Message $message): array => [
                    'message' => (new MessageResource($message))->resolve($request),
                    'container' => ContainerSummary::for($message),
                    /*
                     * Structured segments, never markup — see MessageSearch. An
                     * empty list means there was nothing to highlight, and the
                     * client falls back to the message body itself.
                     */
                    'snippet' => $this->search->segments(
                        $message->getAttribute('search_snippet'),
                    ),
                ])
                ->values()
                ->all(),

            'total' => $found['total'],
            'page' => $found['page'],
            'has_more' => $found['has_more'],
        ]);
    }
}
