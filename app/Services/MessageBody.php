<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * A message's content, in the two forms the database stores.
 *
 * # Why source text rather than HTML
 *
 * Snack stores what the author *typed* — a small Markdown-like source — and
 * never stores rendered HTML. The client turns that source into React elements
 * with a tokenizer, so message content is never interpolated into the DOM as
 * markup and there is no `dangerouslySetInnerHTML` anywhere in the app.
 *
 * The alternative, sanitizing HTML on the way in, makes every message's safety
 * depend on a sanitizer staying ahead of every parser quirk in every browser,
 * forever. Storing source text means a hostile string like `<img onerror=...>`
 * is just characters: it round-trips through the database, the broadcast
 * payload, and the JSON response, and lands in a React text node where it
 * renders as the literal text the author typed. There is no point at which it
 * is parsed as markup.
 *
 * It also keeps editing honest — the composer re-opens exactly what was saved —
 * and leaves the rendering rules free to change later without a data migration.
 *
 * # The plaintext form
 *
 * `plaintext` is the flattened version used for search, sidebar previews, and
 * notification bodies. It is derived here, once, rather than by every consumer
 * that needs a preview.
 */
final readonly class MessageBody
{
    /**
     * Formatting markers understood by the renderer, stripped when flattening.
     * Kept in one place so the server's idea of "markup" cannot drift from the
     * client's.
     */
    private const INLINE_MARKERS = ['**', '~~', '`', '_'];

    private function __construct(
        public string $source,
        public string $plaintext,
    ) {}

    /**
     * Normalize raw composer input.
     *
     * Normalization is deliberately conservative: it fixes line endings, strips
     * control characters that would corrupt the payload, and trims runaway
     * blank lines. It does not attempt to alter or "clean up" the author's
     * words.
     */
    public static function fromInput(string $raw): self
    {
        $source = str($raw)
            // Normalize CRLF/CR to LF so a Windows composer and a Mac one
            // produce byte-identical rows for the same message.
            ->replace(["\r\n", "\r"], "\n")
            // Strip C0 control characters except tab and newline, then the
            // zero-width and bidirectional-override characters that can make a
            // message render differently from what it actually contains.
            ->replaceMatches('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '')
            ->replaceMatches('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '')
            // Three or more consecutive newlines collapse to one blank line.
            ->replaceMatches('/\n{3,}/', "\n\n")
            ->trim()
            ->toString();

        return new self($source, self::flatten($source));
    }

    /**
     * The attributes to persist, ready to merge into a Message.
     *
     * @return array{body: string, body_plaintext: string}
     */
    public function toAttributes(): array
    {
        return [
            'body' => $this->source,
            'body_plaintext' => $this->plaintext,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->source === '';
    }

    /**
     * Strip formatting markers to leave readable prose.
     *
     * Fenced code blocks keep their contents but lose their fences, so a code
     * snippet still produces a useful search hit and a sensible preview.
     */
    private static function flatten(string $source): string
    {
        $text = preg_replace('/^```[a-z0-9+#-]*$/mi', '', $source) ?? $source;
        $text = preg_replace('/^>\s?/m', '', $text) ?? $text;

        return Str::squish(str_replace(self::INLINE_MARKERS, '', $text));
    }
}
