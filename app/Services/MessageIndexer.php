<?php

namespace App\Services;

use App\Jobs\IndexMessage;
use Illuminate\Support\Facades\DB;

/**
 * Maintains `messages.search_vector`.
 *
 * # Why this is not a generated column
 *
 * A `GENERATED ALWAYS AS` tsvector can only see the row it lives on, and a
 * useful message index has to fold in three things that live elsewhere: who sent
 * it, which channel it is in, and what files are attached to it. All three can
 * change *after* the message is written — someone renames a channel, an upload
 * finishes processing — so the vector is a real column, refreshed by
 * {@see IndexMessage}.
 *
 * # Weights
 *
 * A the body, B the sender, C the channel, D the filenames. The letters are
 * Postgres's, and they are what lets the ranking in {@see MessageSearch} prefer
 * a message about invoices over one that merely carries "invoice.pdf".
 *
 * Filenames are flattened to spaces before tokenizing. Left alone, Postgres's
 * parser reads "Q3-report.pdf" as a single filename token, so searching for
 * "report" would not find it — which is the one thing a person searching for a
 * file actually types.
 */
class MessageIndexer
{
    /**
     * The vector expression, with `:dictionary` still to bind.
     *
     * Kept as one string because the per-message refresh and the backfill must
     * produce byte-identical vectors — two definitions that drift would mean
     * search results depending on which path last touched the row.
     */
    private const VECTOR_SQL = <<<'SQL'
        setweight(to_tsvector(:dictionary::regconfig, coalesce(m.body_plaintext, '')), 'A')
     || setweight(to_tsvector(:dictionary::regconfig, coalesce((
            SELECT u.name || ' ' || u.username::text
            FROM users u WHERE u.id = m.sender_id
        ), '')), 'B')
     || setweight(to_tsvector(:dictionary::regconfig, coalesce((
            SELECT c.name FROM channels c WHERE c.id = m.channel_id
        ), '')), 'C')
     || setweight(to_tsvector(:dictionary::regconfig, coalesce((
            SELECT string_agg(regexp_replace(a.original_name, '[^a-zA-Z0-9]+', ' ', 'g'), ' ')
            FROM message_attachments a WHERE a.message_id = m.id
        ), '')), 'D')
    SQL;

    /**
     * Refresh one message's vector.
     */
    public function index(int $messageId): void
    {
        DB::update(
            'UPDATE messages AS m SET search_vector = '.self::VECTOR_SQL.' WHERE m.id = :id',
            ['dictionary' => $this->dictionary(), 'id' => $messageId],
        );
    }

    /**
     * Backfill every message still missing a vector.
     *
     * Chunked by primary key rather than by OFFSET so the sweep does not slow
     * down as it advances, and so a run interrupted halfway can simply be run
     * again — rows already indexed drop out of the `IS NULL` predicate.
     *
     * @param  bool  $all  Re-index rows that already have a vector too, for when
     *                     the dictionary or the weights change.
     * @return int Rows written.
     */
    public function backfill(int $chunk = 500, bool $all = false): int
    {
        $written = 0;
        $cursor = 0;

        do {
            $ids = DB::table('messages')
                ->select('id')
                ->where('id', '>', $cursor)
                ->when(! $all, fn ($query) => $query->whereNull('search_vector'))
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            /*
             * The cursor advances even on a re-index of everything, which is why
             * it is tracked separately from the predicate: with `$all` set, every
             * row matches on every pass, so a query that only said "WHERE
             * search_vector IS NULL OR TRUE" would rewrite the first chunk
             * forever.
             */
            $cursor = (int) $ids->last();

            $written += DB::update(
                'UPDATE messages AS m SET search_vector = '.self::VECTOR_SQL.'
                 WHERE m.id IN ('.$ids->map(fn ($id): int => (int) $id)->implode(',').')',
                ['dictionary' => $this->dictionary()],
            );
        } while (true);

        return $written;
    }

    /**
     * The text search configuration, shared with the query side. Indexing with
     * one dictionary and searching with another silently returns nothing, since
     * the stems would not match.
     */
    public function dictionary(): string
    {
        return (string) config('snack.search.dictionary', 'english');
    }
}
