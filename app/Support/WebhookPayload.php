<?php

namespace App\Support;

use App\Models\IncomingWebhook;
use Illuminate\Support\Str;

/**
 * What an outside system sent, normalized into the two things a delivery needs:
 * the message body, and the card that hangs off it.
 *
 * # Why the Slack shape
 *
 * `{"text": "..."}`, with optional legacy `attachments`, is the payload every
 * existing integration already emits — a Laravel app's Slack notification
 * channel, a GitHub webhook relay, three lines of curl in a deploy script. A
 * cleverer schema of our own would be better in isolation and worse in every
 * way that matters: it would mean nothing already written could be pointed at
 * Snack without being rewritten first, which is the entire value of an incoming
 * webhook.
 *
 * So the shape is theirs. What it *becomes* is Snack's — a normal message with
 * a normal body, plus a structured block in `metadata`.
 *
 * # Why the caller's structure never reaches the renderer intact
 *
 * Everything here is rebuilt key by key rather than passed through. The payload
 * arrives from a caller that no user is accountable for, and it is written into
 * `messages.metadata`, which the client reads back and draws. Copying the array
 * wholesale would mean an unauthenticated request choosing the keys in a column
 * the frontend trusts — so an unknown key is dropped, every string is capped,
 * and a `color` is either one of the four words or a hex triple.
 *
 * The text itself needs no such treatment: it lands in `body` and is rendered as
 * a React text node like every other message. See {@see App\Services\MessageBody}.
 */
final readonly class WebhookPayload
{
    /** The words Slack's `color` accepts, mapped to what the client draws. */
    private const NAMED_COLORS = ['good', 'warning', 'danger', 'default'];

    /**
     * @param  string  $text  The message body.
     * @param  array<int, array<string, mixed>>  $attachments  Normalized cards.
     * @param  string  $username  Who the message is attributed to.
     * @param  string|null  $icon  An emoji shortcode, without the colons.
     */
    private function __construct(
        public string $text,
        public array $attachments,
        public string $username,
        public ?string $icon,
    ) {}

    /**
     * Build from a validated request payload.
     *
     * Validation has already bounded the sizes and the types — see
     * {@see App\Http\Requests\Webhooks\DeliverWebhookRequest} — so what happens
     * here is shaping, not defending. Both still run: the rules give the caller
     * a 422 that says which field was wrong, and this guarantees the shape
     * regardless of how the payload got here.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRequest(array $data, IncomingWebhook $webhook): self
    {
        $attachments = is_array($data['attachments'] ?? null) ? $data['attachments'] : [];

        return new self(
            text: self::text($data),
            attachments: collect($attachments)
                ->filter(fn (mixed $attachment): bool => is_array($attachment))
                ->take(config('snack.webhooks.max_attachments'))
                ->map(fn (array $attachment): array => self::attachment($attachment))
                // An attachment that had nothing renderable in it is dropped
                // here rather than drawn as an empty box.
                ->filter(fn (array $attachment): bool => $attachment !== [])
                ->values()
                ->all(),
            username: self::username($data, $webhook),
            icon: self::icon($data) ?? $webhook->default_icon,
        );
    }

    /**
     * The `webhook` block written into the message's metadata.
     *
     * Carries the display identity as well as the cards, because the message
     * has to keep rendering as what it was posted as: the hook may be renamed
     * or revoked tomorrow, and a year-old deploy notification should still say
     * which system sent it. The same reason a standup answer snapshots the
     * question it was given.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(IncomingWebhook $webhook): array
    {
        return [
            'webhook_id' => $webhook->id,
            'username' => $this->username,
            'icon' => $this->icon,
            'attachments' => $this->attachments,
        ];
    }

    /**
     * Whether there is anything worth posting.
     *
     * A delivery with neither text nor a card would be an empty message in a
     * channel, which is noise with no content — the caller is told so with a
     * 422 rather than having it silently posted.
     */
    public function isEmpty(): bool
    {
        return trim($this->text) === '' && $this->attachments === [];
    }

    /**
     * The body, falling back to the first card's own text.
     *
     * Slack lets a payload carry only `attachments`, and clients that do it that
     * way are common enough to matter. Snack's message list renders bodies, and
     * a row with an empty body reads as a glitch — so the card's title or text
     * is promoted to be the message, and the card still draws beneath it.
     *
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data): string
    {
        $text = is_string($data['text'] ?? null) ? $data['text'] : '';

        if (trim($text) !== '') {
            return $text;
        }

        $first = collect($data['attachments'] ?? [])
            ->filter(fn (mixed $attachment): bool => is_array($attachment))
            ->first();

        if (! is_array($first)) {
            return '';
        }

        foreach (['fallback', 'title', 'text', 'pretext'] as $key) {
            if (is_string($first[$key] ?? null) && trim($first[$key]) !== '') {
                return $first[$key];
            }
        }

        return '';
    }

    /**
     * One card, rebuilt key by key. See the class note for why nothing is
     * copied across.
     *
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>
     */
    private static function attachment(array $attachment): array
    {
        $card = array_filter([
            'color' => self::color($attachment['color'] ?? null),
            'pretext' => self::line($attachment['pretext'] ?? null),
            'author_name' => self::line($attachment['author_name'] ?? null, 120),
            'title' => self::line($attachment['title'] ?? null, 300),
            'title_link' => self::url($attachment['title_link'] ?? null),
            'text' => self::line($attachment['text'] ?? null, 2_000),
            'footer' => self::line($attachment['footer'] ?? null, 200),
            'ts' => self::timestamp($attachment['ts'] ?? null),
        ], fn (mixed $value): bool => $value !== null);

        $fields = self::fields($attachment['fields'] ?? null);

        if ($fields !== []) {
            $card['fields'] = $fields;
        }

        /*
         * A card that is nothing but a colour is a coloured bar with no content.
         * It has no `title_link` to click and nothing to read, so it is not a
         * card at all — the caller gets their `text` as the message and nothing
         * else.
         */
        if (array_keys($card) === ['color']) {
            return [];
        }

        return $card;
    }

    /**
     * @return array<int, array{title: string, value: string, short: bool}>
     */
    private static function fields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        return collect($fields)
            ->filter(fn (mixed $field): bool => is_array($field))
            ->take(config('snack.webhooks.max_fields'))
            ->map(fn (array $field): array => [
                'title' => self::line($field['title'] ?? null, 120) ?? '',
                'value' => self::line($field['value'] ?? null, 500) ?? '',
                // Slack's flag for a half-width field. Cast rather than trusted:
                // the JSON may carry it as "true", 1, or nothing at all.
                'short' => filter_var($field['short'] ?? false, FILTER_VALIDATE_BOOL),
            ])
            ->filter(fn (array $field): bool => $field['title'] !== '' || $field['value'] !== '')
            ->values()
            ->all();
    }

    /**
     * The name this delivery is attributed to.
     *
     * A per-delivery override is what lets one hook serve a whole pipeline —
     * "CI", "Deploy", "Alerts" from the same URL — which is how Slack's is used
     * in practice. It is a display string and nothing else: the *account* is the
     * hook's bot either way, so a payload cannot impersonate a colleague by
     * claiming their name. The client renders it beside a bot badge.
     *
     * @param  array<string, mixed>  $data
     */
    private static function username(array $data, IncomingWebhook $webhook): string
    {
        $username = self::line($data['username'] ?? null, 80);

        return $username ?? $webhook->displayName();
    }

    /**
     * An emoji shortcode, with Slack's colons stripped.
     *
     * Only the `:shortcode:` form is taken. Slack also accepts `icon_url`, and
     * it is deliberately ignored: honouring it would have Snack fetch and render
     * an arbitrary remote image on the say-so of an unauthenticated request,
     * which is a request-forgery vector and a tracking pixel in one.
     *
     * @param  array<string, mixed>  $data
     */
    private static function icon(array $data): ?string
    {
        $icon = is_string($data['icon_emoji'] ?? null) ? trim($data['icon_emoji']) : '';

        $shortcode = trim($icon, ':');

        // Shortcodes only: letters, digits, underscores, hyphens, plus. Anything
        // else is a string somebody hoped would be rendered as something else.
        return preg_match('/^[a-z0-9_+-]{1,64}$/i', $shortcode) === 1
            ? Str::lower($shortcode)
            : null;
    }

    /**
     * Slack's `color`: one of four words, or a hex triple.
     *
     * Anything else becomes null rather than an error. This is the only field
     * where a caller's mistake would be invisible to them — a rejected delivery
     * over a mistyped colour would be a build pipeline going red for a shade —
     * so it degrades to the default bar.
     */
    private static function color(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }

        $color = Str::lower(trim($color));

        if (in_array($color, self::NAMED_COLORS, true)) {
            return $color;
        }

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : null;
    }

    /**
     * A single-line display string, trimmed and capped.
     *
     * Newlines survive in the longer fields — a card's `text` is a paragraph —
     * but control characters do not, for the reason
     * {@see App\Services\MessageBody} strips them: a string that renders
     * differently from what it contains.
     */
    private static function line(mixed $value, int $limit = 500): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = str($value)
            ->replace(["\r\n", "\r"], "\n")
            ->replaceMatches('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '')
            ->replaceMatches('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '')
            ->trim()
            ->limit($limit)
            ->toString();

        return $clean === '' ? null : $clean;
    }

    /**
     * A link on a card's title.
     *
     * http and https only. A `javascript:` or `data:` URL here would be an
     * unauthenticated caller putting a script in a link that a colleague is
     * invited to click, which is the one thing a card must not be able to do.
     */
    private static function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(Str::lower(parse_url($url, PHP_URL_SCHEME) ?: ''), ['http', 'https'], true)
            ? Str::limit($url, 2_000, '')
            : null;
    }

    /**
     * Slack's `ts`: a Unix timestamp shown in the card's footer.
     *
     * Kept as an integer and rendered client-side in the reader's locale.
     * Bounded to a plausible range so a card cannot claim to be from the year
     * 40000 and push the footer through the layout.
     */
    private static function timestamp(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > 0 && $timestamp < 4_102_444_800 ? $timestamp : null;
    }
}
