<?php

namespace App\Services;

use App\Enums\MentionType;

/**
 * Finds the mentions in a message's source text.
 *
 * Pure text in, tokens out — no database, no authorization. Resolving those
 * tokens to real people, and deciding who is allowed to address whom, is
 * {@see App\Actions\Messages\SyncMentions}. Keeping the two apart is what makes
 * the grammar testable without a workspace.
 *
 * # Code is not a mention
 *
 * Code spans and fenced blocks are stripped before scanning, so `@ada` in a
 * snippet notifies nobody. This has to match the client's tokenizer, where code
 * consumes its contents whole and never renders a mention — otherwise a message
 * would highlight a mention it did not send, or send one it did not highlight.
 *
 * # The grammar
 *
 * ```
 * "@" username   a person, by their unique handle
 * "@" everyone   every member of the container
 * "@" here       every member currently online
 * "#" channel    a link to a channel; notifies nobody
 * ```
 *
 * (Written with the sigils quoted so the formatter does not read them as
 * PHPDoc tags and reflow the table.)
 *
 * A handle matches the characters a username may contain. The parser does not
 * check that any of them exist — an unresolvable handle is simply not a mention
 * once SyncMentions fails to find it.
 */
final readonly class MentionParser
{
    /**
     * Usernames are generated from names and may carry digits, dots, hyphens,
     * and underscores. A trailing dot is excluded so "ask @ada." reads as a
     * sentence rather than as a handle called "ada.".
     */
    private const HANDLE = '[A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_])?';

    /**
     * The mentions in a body, de-duplicated and lowercased.
     *
     * @return array{
     *     usernames: array<int, string>,
     *     broadcasts: array<int, MentionType>,
     *     channels: array<int, string>,
     * }
     */
    public static function extract(string $source): array
    {
        $text = self::withoutCode($source);

        preg_match_all('/(?<![\w@])@('.self::HANDLE.')/', $text, $handles);
        preg_match_all('/(?<![\w#])#('.self::HANDLE.')/', $text, $channels);

        $found = array_map(mb_strtolower(...), $handles[1]);

        $broadcasts = [];

        foreach ([MentionType::Everyone, MentionType::Here] as $type) {
            if (in_array($type->value, $found, true)) {
                $broadcasts[] = $type;
            }
        }

        // "@everyone" is a broadcast, not somebody's handle, so it must not also
        // be looked up as a username.
        $usernames = array_values(array_diff(
            array_unique($found),
            [MentionType::Everyone->value, MentionType::Here->value],
        ));

        return [
            'usernames' => $usernames,
            'broadcasts' => $broadcasts,
            'channels' => array_values(array_unique(array_map(mb_strtolower(...), $channels[1]))),
        ];
    }

    /**
     * Blank out fenced blocks and inline code.
     *
     * Replaced with spaces rather than removed so that the surrounding text
     * cannot be spliced into a handle that was never written — deleting the
     * code in "`x`@ada" outright would leave the two halves adjacent.
     */
    private static function withoutCode(string $source): string
    {
        $blank = fn (array $match): string => str_repeat(' ', mb_strlen($match[0]));

        // Fenced blocks first: inside them, backticks are content.
        $text = preg_replace_callback('/```.*?(?:```|\z)/s', $blank, $source) ?? $source;

        return preg_replace_callback('/`[^`\n]+`/', $blank, $text) ?? $text;
    }
}
