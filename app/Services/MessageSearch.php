<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Support\SearchQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Full-text search over a workspace's messages.
 *
 * Reads `messages.search_vector`, which {@see MessageIndexer} maintains — see
 * there for why it is a real column rather than a generated one, and what the
 * A/B/C/D weights mean.
 *
 * # Visibility comes first
 *
 * The one thing this must never do is find a message the searcher could not have
 * read by navigating to it. Search starts from the whole workspace rather than
 * from an authorized container, so every query is narrowed by
 * {@see MessageVisibility} before anything else is applied — and the filters the
 * user typed are applied *on top of* that constraint, never in place of it. A
 * `in:#private-channel` naming somewhere they were never invited intersects with
 * nothing.
 *
 * # Snippets are structured, not markup
 *
 * `ts_headline` marks the matched words, and every implementation reaches for
 * `<b>` to do it. This application renders no HTML from message content anywhere
 * — see {@see MessageBody} — so the marks are sentinel control characters that
 * are split apart here into `{text, highlight}` segments. The client draws them
 * as React elements. There is no point at which a snippet is parsed as markup,
 * which keeps the guarantee the rest of the message pipeline makes.
 *
 * The sentinels are `\x02` and `\x03`, chosen because MessageBody strips exactly
 * that range from every body it stores — so no message can contain one and be
 * mistaken for a highlight boundary.
 */
class MessageSearch
{
    private const HIGHLIGHT_START = "\x02";

    private const HIGHLIGHT_END = "\x03";

    public function __construct(private readonly MessageVisibility $visibility) {}

    /**
     * @return array{results: Collection<int, Message>, total: int, page: int, has_more: bool}
     */
    public function search(
        Workspace $workspace,
        User $user,
        SearchQuery $query,
        int $page = 1,
    ): array {
        $perPage = (int) config('snack.search.page_size', 25);
        $page = max(1, $page);

        if ($query->isEmpty()) {
            return ['results' => new Collection, 'total' => 0, 'page' => 1, 'has_more' => false];
        }

        $base = $this->constrain(Message::query(), $workspace, $user, $query);

        /*
         * Counted before the page is taken, because the result header says "48
         * results" and the alternative — counting what came back — says "25" on
         * every search that matched more than a page.
         */
        $total = (clone $base)->toBase()->getCountForPagination();

        $results = $this->select($base, $query)
            ->with([
                'sender:id,name,username,avatar_path,title,presence_status',
                'channel:id,name,slug,type,workspace_id',
                'conversation',
                'attachments',
            ])
            ->forPage($page, $perPage)
            ->get();

        return [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'has_more' => $page * $perPage < $total,
        ];
    }

    /**
     * Split a `ts_headline` result into renderable segments.
     *
     * Returns a single unhighlighted segment when there is nothing to mark,
     * which is what a filters-only search produces.
     *
     * @return array<int, array{text: string, highlight: bool}>
     */
    public function segments(?string $snippet): array
    {
        if ($snippet === null || $snippet === '') {
            return [];
        }

        $segments = [];

        foreach (explode(self::HIGHLIGHT_START, $snippet) as $index => $chunk) {
            if ($index === 0) {
                if ($chunk !== '') {
                    $segments[] = ['text' => $chunk, 'highlight' => false];
                }

                continue;
            }

            [$highlighted, $rest] = array_pad(explode(self::HIGHLIGHT_END, $chunk, 2), 2, '');

            if ($highlighted !== '') {
                $segments[] = ['text' => $highlighted, 'highlight' => true];
            }

            if ($rest !== '') {
                $segments[] = ['text' => $rest, 'highlight' => false];
            }
        }

        return $segments;
    }

    /**
     * Visibility, then the user's filters.
     *
     * @param  Builder<Message>  $messages
     * @return Builder<Message>
     */
    private function constrain(
        Builder $messages,
        Workspace $workspace,
        User $user,
        SearchQuery $query,
    ): Builder {
        $constrained = $this->visibility->apply($messages, $workspace, $user);

        if ($query->hasTerms()) {
            $constrained->whereRaw(
                'messages.search_vector @@ websearch_to_tsquery(?::regconfig, ?)',
                [$this->dictionary(), $query->terms],
            );
        }

        /*
         * `from:` resolves against the workspace's own members. A username from
         * another tenant matches no member and therefore no message, rather than
         * matching that person's messages here.
         */
        if ($query->from !== []) {
            $constrained->whereIn('sender_id', $workspace->users()
                ->whereIn('users.username', $query->from)
                ->select('users.id'));
        }

        if ($query->in !== []) {
            $constrained->whereIn('channel_id', Channel::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('slug', $query->in)
                ->select('id'));
        }

        if ($query->requiresFile) {
            $constrained->where('has_attachments', true);
        }

        if ($query->after !== null) {
            $constrained->where('created_at', '>=', $query->after);
        }

        if ($query->before !== null) {
            // Exclusive of the named day's own messages would surprise everyone
            // who typed it, so the whole day counts as "before tomorrow".
            $constrained->where('created_at', '<', $query->before->addDay());
        }

        return $constrained;
    }

    /**
     * The ranked projection.
     *
     * A filters-only search has no tsquery to rank against, so it falls back to
     * newest-first — which is the only sensible order for "everything Ada shared
     * in #design".
     *
     * @param  Builder<Message>  $messages
     * @return Builder<Message>
     */
    private function select(Builder $messages, SearchQuery $query): Builder
    {
        $messages->select('messages.*');

        if (! $query->hasTerms()) {
            return $messages->orderByDesc('messages.id');
        }

        /** @var array<int, float> $weights */
        $weights = config('snack.search.weights', [0.1, 0.2, 0.4, 1.0]);

        return $messages
            ->selectRaw(
                'ts_rank(?::float4[], messages.search_vector, websearch_to_tsquery(?::regconfig, ?)) AS search_rank',
                ['{'.implode(',', $weights).'}', $this->dictionary(), $query->terms],
            )
            ->selectRaw(
                'ts_headline(?::regconfig, messages.body_plaintext, websearch_to_tsquery(?::regconfig, ?), ?) AS search_snippet',
                [
                    $this->dictionary(),
                    $this->dictionary(),
                    $query->terms,
                    /*
                     * Sentinels rather than markup — see the class docblock.
                     * Fragments keep a hit from the middle of a long message
                     * readable instead of showing its first two lines; the
                     * ellipsis between them is the only decoration.
                     */
                    'StartSel='.self::HIGHLIGHT_START
                        .',StopSel='.self::HIGHLIGHT_END
                        .',MaxFragments=2,FragmentDelimiter= … ,MaxWords=26,MinWords=10',
                ],
            )
            // The id tiebreak keeps the order stable across pages: without it,
            // two equally-ranked messages can swap places between page 1 and 2
            // and one of them is never shown.
            ->orderByDesc('search_rank')
            ->orderByDesc('messages.id');
    }

    private function dictionary(): string
    {
        return (string) config('snack.search.dictionary', 'english');
    }
}
