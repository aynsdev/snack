<?php

namespace App\Ai\Tools;

use App\Models\Message;
use App\Services\MessageSearch;
use App\Support\SearchQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * "What did anyone say about X?"
 *
 * Runs the workspace's own full-text search — the one behind the search box —
 * as the person who asked. {@see MessageSearch} applies
 * {@see App\Services\MessageVisibility} before anything else, so a private
 * channel the asker was never invited to intersects with nothing here, exactly
 * as it does when they type into the box themselves.
 *
 * The same `from:`/`in:`/`has:file` grammar works, because the model is handed
 * the raw string and {@see SearchQuery} parses it. That is worth more than a
 * structured schema would be: the model already knows how to write those
 * filters from the instructions, and the parser is the one that has been
 * hardened against them naming things the searcher cannot see.
 */
class SearchMessages extends BiteTool implements Tool
{
    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Search everything said in this workspace's channels and direct messages.
        Use this for any question about what was decided, discussed, announced,
        or asked — it is the primary way to answer questions about the workspace.

        The query supports filters, which may be combined with the search words:
        `from:@username` limits to one author, `in:#channel` to one channel,
        `has:file` to messages carrying an attachment, and `before:2026-01-30` /
        `after:2026-01-01` to a date range.

        Results are already limited to what the person asking is allowed to read.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $raw = $this->term($this->arg($request, 'query'), 300);

        if ($raw === '') {
            return 'No query was given. Provide search words.';
        }

        $query = SearchQuery::parse($raw);

        if ($query->isEmpty()) {
            return "The query [{$raw}] is too short to search for. Use at least two characters of actual search text.";
        }

        $found = app(MessageSearch::class)->search(
            workspace: $this->context->workspace,
            user: $this->context->user,
            query: $query,
            within: $this->context->readScope,
        );

        $limit = $this->limit($this->arg($request, 'limit'), 10, 25);

        $rows = $found['results']
            ->take($limit)
            ->map(fn (Message $message): string => $this->row($message))
            ->all();

        return $this->list($rows, "No messages match [{$raw}].")
            ."\n\n".($found['total'] > count($rows)
                ? "Showing {$this->count($rows)} of {$found['total']} matches."
                : "{$found['total']} match(es).");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The words to search for, optionally with from:/in:/has:/before:/after: filters.')
                ->required(),
            'limit' => $schema->integer()
                ->description('How many messages to return. Defaults to 10, maximum 25.')
                ->min(1)
                ->max(25),
        ];
    }

    /**
     * One result, as the model should see it.
     *
     * The body is truncated rather than sent whole: a search that matched thirty
     * messages would otherwise put thirty full messages into the context, and
     * the model's next move is to read the ones that look relevant, not all of
     * them. It is told the id so it can.
     */
    private function row(Message $message): string
    {
        $where = $message->channel !== null
            ? '#'.$message->channel->name
            : 'a direct message';

        $author = $message->sender->name;
        $when = $message->created_at?->toDayDateTimeString() ?? 'unknown time';

        $body = str($message->body)->squish()->limit(400)->toString();

        return "message #{$message->id} — {$author} in {$where}, {$when}\n  {$body}";
    }

    /**
     * @param  array<int, string>  $rows
     */
    private function count(array $rows): int
    {
        return count($rows);
    }
}
