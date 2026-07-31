<?php

namespace App\Support;

use App\Services\MessageSearch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * A search box's contents, parsed into terms and filters.
 *
 * People type `budget from:@ada in:#finance has:file after:2026-01-01` into one
 * box and expect it to mean something. This turns that into structured values
 * {@see MessageSearch} can put into a query, leaving whatever is
 * left over as the words to actually search for.
 *
 * # Everything here is still untrusted
 *
 * Parsing a handle out of `from:@ada` does not make "ada" a user. Nothing in
 * this class touches the database, and nothing it produces is interpolated into
 * SQL: the values are matched against the viewer's own visible channels and the
 * workspace's own members, so a filter naming something the searcher cannot see
 * narrows the result set to nothing rather than reaching into it.
 *
 * # Why unknown keys stay in the terms
 *
 * `re:` in `re: the thing we discussed` is not a filter, and neither is a URL's
 * `https:`. Only the recognized keys are lifted out; anything else is left in
 * the text where the author clearly meant it to be.
 */
final readonly class SearchQuery
{
    /**
     * The shortest query worth running.
     *
     * A constant rather than configuration, deliberately: this is a property of
     * the parser — it is what makes `isEmpty()` answerable — and reaching into
     * the container from a value object would mean this class could not be
     * constructed or reasoned about without booting the framework.
     *
     * Two characters match a large fraction of a busy workspace, and the result
     * is a slow query whose answer is useless.
     */
    public const MIN_TERM_LENGTH = 2;

    /**
     * @param  string  $terms  The free text, filters removed.
     * @param  array<int, string>  $from  Usernames, without the leading `@`.
     * @param  array<int, string>  $in  Channel slugs, without the leading `#`.
     */
    public function __construct(
        public string $raw,
        public string $terms,
        public array $from = [],
        public array $in = [],
        public bool $requiresFile = false,
        public ?CarbonImmutable $before = null,
        public ?CarbonImmutable $after = null,
    ) {}

    public static function parse(string $raw): self
    {
        $raw = str($raw)->squish()->limit(300, '')->toString();

        $from = [];
        $in = [];
        $requiresFile = false;
        $before = null;
        $after = null;

        /*
         * A value may be quoted, so `in:"design review"` survives — channel
         * slugs cannot contain spaces, but the same grammar serves every key and
         * a person typing quotes should not be punished for it.
         */
        $terms = (string) preg_replace_callback(
            '/\b(from|in|has|before|after):("[^"]*"|[^\s]+)/i',
            function (array $match) use (&$from, &$in, &$requiresFile, &$before, &$after): string {
                $key = strtolower($match[1]);
                $value = trim($match[2], '"');

                match ($key) {
                    'from' => $from[] = ltrim($value, '@'),
                    'in' => $in[] = ltrim($value, '#'),
                    // Only `has:file` means anything today. Anything else is a
                    // filter nobody implemented, and silently ignoring it is
                    // better than silently returning everything.
                    'has' => $requiresFile = $requiresFile || strtolower($value) === 'file',
                    'before' => $before = self::date($value),
                    'after' => $after = self::date($value),
                    default => null,
                };

                // Consumed: removed from the text so it is not also searched for.
                return '';
            },
            $raw,
        );

        return new self(
            raw: $raw,
            terms: str($terms)->squish()->toString(),
            from: array_values(array_filter(array_unique($from), fn (string $value): bool => $value !== '')),
            in: array_values(array_filter(array_unique($in), fn (string $value): bool => $value !== '')),
            requiresFile: $requiresFile,
            before: $before,
            after: $after,
        );
    }

    /**
     * Whether there is anything at all to search for.
     *
     * Filters alone are a valid search — `in:#design has:file` means "show me
     * what has been shared in design" — so this is not simply "are there words".
     */
    public function isEmpty(): bool
    {
        return ! $this->hasTerms() && ! $this->hasFilters();
    }

    public function hasTerms(): bool
    {
        return mb_strlen($this->terms) >= self::MIN_TERM_LENGTH;
    }

    public function hasFilters(): bool
    {
        return $this->from !== []
            || $this->in !== []
            || $this->requiresFile
            || $this->before !== null
            || $this->after !== null;
    }

    /**
     * A date filter, or null if it is not one.
     *
     * Anything Carbon cannot read is dropped rather than raised: a half-typed
     * `after:2026-` is a search still being written, not an error to interrupt
     * somebody with.
     */
    private static function date(string $value): ?CarbonImmutable
    {
        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return CarbonImmutable::instance($date)->startOfDay();
    }
}
