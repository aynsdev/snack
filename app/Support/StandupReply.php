<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A plain message, read as an answer to a standup.
 *
 * # Why a typed reply is answerable at all
 *
 * The card is the good way to answer — it asks everything at once and can be
 * corrected before it is published — but it is not the only way people try. The
 * bot's own prompt ends "Answer here and I will post it to #channel", and a
 * person who does exactly that, on their phone, gets nothing back. Refusing the
 * reply is not a neutral outcome: they believe they have answered, the channel
 * never hears from them, and the nudge that arrives an hour later reads as the
 * app being broken.
 *
 * So a reply in the standup DM is parsed. What it is *not* is a state machine:
 * nothing waits for a second message, nothing asks a follow-up question, and a
 * reply that cannot be split is still an answer rather than an error. See
 * {@see App\Actions\Standups\AnswerStandupFromReply}.
 *
 * # The ladder
 *
 * Splitting one blob across N questions is a guess, so the guesses are ordered
 * from "the person told us" to "we are inferring", and the first one that fits
 * wins:
 *
 * 1. A skip phrase — "nothing to report", and nothing else in the message.
 * 2. Numbered markers (`1.`, `2)`), which is the shape the prompt itself lists
 *    the questions in and therefore the shape people copy.
 * 3. The questions repeated as headings, which is what pasting the prompt back
 *    and typing under it produces.
 * 4. Blank-line separated blocks, one per question.
 * 5. One line per question.
 * 6. Anything else: the whole message, against the first question.
 *
 * Rules 4 and 5 apply only when the count matches exactly. A two-paragraph
 * answer to three questions is not a mis-split waiting to happen — it falls
 * through to rule 6 and lands whole, which is recoverable by reading it, unlike
 * an answer filed under the wrong question.
 */
final readonly class StandupReply
{
    /**
     * Whole-message phrases that mean "nothing from me today".
     *
     * Matched against the entire message, never against a line inside it:
     * "nothing blocking me, shipping the parser" is an answer, and treating the
     * word "nothing" as a skip anywhere in a sentence is how somebody's actual
     * update is silently dropped.
     */
    private const array SKIP_PHRASES = [
        'skip',
        'skipped',
        'skipping',
        'skip today',
        'pass',
        'nothing',
        'nothing today',
        'nothing to report',
        'nothing to report today',
        'nothing new',
        'no update',
        'no updates',
        'na',
        'n/a',
    ];

    /**
     * @param  array<int, string>  $answers  Positional, aligned with the questions.
     */
    private function __construct(
        public array $answers,
        public bool $skipped,
    ) {}

    /**
     * @param  array<int, string>  $questions  The questions as they were asked —
     *                                         the prompt's snapshot, not the standup's current wording.
     */
    public static function parse(string $body, array $questions): self
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        $count = max(count($questions), 1);

        if ($body === '') {
            return new self([], false);
        }

        if (self::readsAsSkip($body)) {
            return new self([], true);
        }

        $sections = self::numbered($body, $count)
            ?? self::headed($body, $questions)
            ?? self::positional($body, $count);

        if ($sections !== null) {
            return new self($sections, false);
        }

        // Rule 6. One question, or a shape nothing recognised: the message is
        // the answer, kept whole and filed against the first question.
        return new self([$body], false);
    }

    /**
     * Nothing usable in the message — an attachment with no text, or a body that
     * was only whitespace.
     */
    public function isEmpty(): bool
    {
        return ! $this->skipped && collect($this->answers)->every(
            fn (string $answer): bool => trim($answer) === '',
        );
    }

    /**
     * Rule 1.
     *
     * Emphasis and trailing punctuation are stripped first, because "*nothing to
     * report!*" is the same sentence and a person who typed it will not accept
     * that it was not.
     */
    private static function readsAsSkip(string $body): bool
    {
        $normalized = Str::of($body)
            ->replaceMatches('/[*_~`]/', '')
            ->lower()
            ->replaceMatches('/[\s]+/', ' ')
            ->trim(' .!,;:-')
            ->value();

        return in_array($normalized, self::SKIP_PHRASES, true);
    }

    /**
     * Rule 2: `1.`, `2)`, `3 -`.
     *
     * Returns null unless the markers form a plausible answer set — every number
     * inside the question range, and none of them repeated. A message that
     * happens to contain "1. " in the middle of a sentence about a version number
     * does not get to reorganise somebody's standup.
     *
     * Text before the first marker is dropped. It is where "here you go:" lives,
     * and attaching it to question one reads as an answer the person did not
     * give.
     *
     * @return array<int, string>|null
     */
    private static function numbered(string $body, int $count): ?array
    {
        $lines = explode("\n", $body);
        $sections = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d{1,2})\s*[.):\-]\s*(.*)$/', $line, $matches) === 1) {
                $position = (int) $matches[1] - 1;

                if ($position < 0 || $position >= $count || isset($sections[$position])) {
                    return null;
                }

                $current = $position;
                $sections[$position] = [$matches[2]];

                continue;
            }

            if ($current !== null) {
                $sections[$current][] = $line;
            }
        }

        return $sections === [] ? null : self::flatten($sections, $count);
    }

    /**
     * Rule 3: the questions themselves, used as headings.
     *
     * The comparison is loose on purpose — case, emphasis, a leading bullet and
     * the trailing "?" or ":" are all noise a person adds or drops without
     * meaning anything by it.
     *
     * @param  array<int, string>  $questions
     * @return array<int, string>|null
     */
    private static function headed(string $body, array $questions): ?array
    {
        if ($questions === []) {
            return null;
        }

        $keyed = [];

        foreach ($questions as $index => $question) {
            $key = self::normalize($question);

            if ($key !== '') {
                $keyed[$key] = $index;
            }
        }

        $sections = [];
        $current = null;

        foreach (explode("\n", $body) as $line) {
            $key = self::normalize($line);
            $position = $keyed[$key] ?? null;

            if ($position !== null && ! isset($sections[$position])) {
                $current = $position;
                $sections[$position] = [];

                continue;
            }

            if ($current !== null) {
                $sections[$current][] = $line;
            }
        }

        return $sections === [] ? null : self::flatten($sections, count($questions));
    }

    /**
     * Rules 4 and 5: as many blocks, or as many lines, as there are questions.
     *
     * Only ever with an exact match, and never for a single question — a
     * one-question standup answered in three paragraphs is three paragraphs of
     * one answer, and rule 6 keeps it that way.
     *
     * @return array<int, string>|null
     */
    private static function positional(string $body, int $count): ?array
    {
        if ($count < 2) {
            return null;
        }

        $blocks = collect(preg_split('/\n\s*\n/', $body) ?: [])
            ->map(fn (string $block): string => trim($block))
            ->filter(fn (string $block): bool => $block !== '')
            ->values();

        if ($blocks->count() === $count) {
            return $blocks->all();
        }

        $lines = collect(explode("\n", $body))
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values();

        return $lines->count() === $count ? $lines->all() : null;
    }

    /**
     * Sections back into a dense positional list, with the gaps blank.
     *
     * A person who answered questions one and three leaves two empty, and empty
     * is dropped downstream — the pair never reaches the channel, so the summary
     * shows the two things they said rather than a question with silence under
     * it.
     *
     * @param  array<int, array<int, string>>  $sections
     * @return array<int, string>
     */
    private static function flatten(array $sections, int $count): array
    {
        $answers = [];

        for ($position = 0; $position < $count; $position++) {
            $answers[$position] = trim(implode("\n", $sections[$position] ?? []));
        }

        return $answers;
    }

    /**
     * A line reduced to the words in it, for comparing against a question.
     */
    private static function normalize(string $line): string
    {
        return Str::of($line)
            ->replaceMatches('/[*_~`]/', '')
            ->replaceMatches('/^\s*[-–—•>]+\s*/', '')
            ->replaceMatches('/^\s*\d{1,2}\s*[.)]\s*/', '')
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim(' ?:.')
            ->value();
    }
}
