<?php

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A delivery from an outside system.
 *
 * The one request in Snack with no user behind it. {@see authorize()} returns
 * true because there is nobody to authorize: the token in the URL is the whole
 * credential, and the controller has already resolved it to a live hook before
 * this request is validated. See the incoming_webhooks migration for why that is
 * enough.
 *
 * # Why the payload is accepted two ways
 *
 * `application/json` is what a modern client sends and what the docs show.
 * `payload=<json>`, form-encoded, is what Slack's own endpoint has always
 * accepted and what a decade of integrations therefore emit — including several
 * of Laravel's own notification setups when pointed at a Slack-shaped URL.
 * Supporting both is a dozen lines in {@see prepareForValidation()} and is the
 * difference between "change the URL" and "rewrite the integration".
 */
class DeliverWebhookRequest extends FormRequest
{
    /** Top-level keys that hold one value, not a list. */
    private const SINGLE_KEYS = ['text', 'username', 'icon_emoji', 'thread_key', 'thread_id'];

    /** Card keys that hold one value, not a list. */
    private const CARD_SINGLE_KEYS = ['color', 'fallback', 'pretext', 'author_name', 'title', 'title_link', 'text', 'footer', 'ts'];

    /** Field keys that hold one value, not a list. */
    private const FIELD_SINGLE_KEYS = ['title', 'value', 'short'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Unwrap Slack's form-encoded envelope before anything else looks at the
     * input.
     *
     * A malformed `payload` is left exactly as it arrived rather than being
     * turned into an error here: the rules below then fail on a missing `text`,
     * which is a 422 naming a field the caller can find, instead of a bare
     * "invalid JSON" from a layer they cannot see.
     */
    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload');

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            if (is_array($decoded)) {
                $this->replace($decoded);
            }
        }

        $this->replace(self::normalizeShapes($this->all()));
    }

    /**
     * Forgive the two shapes automation tools produce when building JSON from a
     * form rather than by hand.
     *
     * - A lone card as an object, `"attachments": {...}`, rather than a list of
     *   one. It is wrapped.
     * - A single value wrapped in a list, `"color": ["danger"]`, because the
     *   tool treats every field as repeatable. It is unwrapped.
     *
     * Without this both were dropped in silence — the object's keys were read as
     * cards and the one-element lists as non-strings — so the message posted
     * with no card and no error, which is the hardest kind of bug for the
     * person on the other end to find. Only the obvious cases are touched: a
     * list of two values is still ambiguous, and still dropped.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeShapes(array $data): array
    {
        $data = self::unwrapSingles($data, self::SINGLE_KEYS);

        if (! is_array($data['attachments'] ?? null)) {
            return $data;
        }

        $data['attachments'] = array_map(function (mixed $card): mixed {
            if (! is_array($card)) {
                return $card;
            }

            $card = self::unwrapSingles($card, self::CARD_SINGLE_KEYS);

            if (is_array($card['fields'] ?? null)) {
                $card['fields'] = array_map(
                    fn (mixed $field): mixed => is_array($field) ? self::unwrapSingles($field, self::FIELD_SINGLE_KEYS) : $field,
                    self::asList($card['fields']),
                );
            }

            return $card;
        }, self::asList($data['attachments']));

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<mixed>
     */
    private static function asList(array $value): array
    {
        return array_is_list($value) ? $value : [$value];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private static function unwrapSingles(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_array($value) && count($value) === 1 && array_is_list($value) && ! is_array($value[0])) {
                $data[$key] = $value[0];
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Required unless the payload carries cards instead. Slack allows a
             * body-less attachments-only delivery and enough clients emit one
             * that refusing it would break integrations for no gain — the
             * message body is filled from the first card. See
             * {@see App\Support\WebhookPayload::text()}.
             */
            'text' => [
                'required_without:attachments',
                'nullable',
                'string',
                'max:'.config('snack.webhooks.max_text_length'),
            ],

            // A display string only. It cannot change which account posts — see
            // WebhookPayload::username() — so there is nothing to impersonate.
            'username' => ['sometimes', 'nullable', 'string', 'max:80'],

            // With or without Slack's colons; the parser strips them either way.
            'icon_emoji' => ['sometimes', 'nullable', 'string', 'max:66'],

            /*
             * Threading, two ways. `thread_key` is the caller's own id for the
             * thing it is narrating — the first delivery with a key starts the
             * thread, later ones reply in it. `thread_id` is a `message_id` an
             * earlier delivery returned. One or the other: both at once would
             * name two threads and leave Snack to guess.
             */
            'thread_key' => ['sometimes', 'nullable', 'string', 'max:255', 'prohibits:thread_id'],
            'thread_id' => ['sometimes', 'nullable', 'integer', 'min:1'],

            'attachments' => ['sometimes', 'array', 'max:'.config('snack.webhooks.max_attachments')],

            /*
             * The contents of a card are bounded here but not enumerated.
             * {@see WebhookPayload} rebuilds every card key by key and drops
             * what it does not recognise, so an unknown field is already
             * impossible to persist — and rejecting one outright would break a
             * caller sending a valid Slack payload with a field Snack has no
             * use for, which is most of them.
             *
             * What these rules do is stop the pathological shapes early, before
             * a parser walks them: a hundred-thousand-element `fields` array, or
             * a `text` that is itself an array.
             */
            'attachments.*' => ['array'],
            'attachments.*.fields' => ['sometimes', 'array', 'max:'.config('snack.webhooks.max_fields')],
            'attachments.*.fields.*' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required_without' => 'A delivery needs "text", or at least one attachment.',
            'text.max' => 'That text is longer than a webhook message may be.',
            'attachments.max' => 'That is more attachments than one delivery may carry.',
            'thread_key.prohibits' => 'Send "thread_key" or "thread_id", not both.',
        ];
    }

    /**
     * The caller's own name for the thread this delivery belongs to.
     */
    public function threadKey(): ?string
    {
        $key = $this->input('thread_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * The message this delivery replies under, as an earlier delivery's `message_id`.
     */
    public function threadId(): ?int
    {
        return $this->filled('thread_id') ? $this->integer('thread_id') : null;
    }

    /**
     * The payload as an array, ready for {@see WebhookPayload::fromRequest()}.
     *
     * `validated()` deliberately is not used: it returns only the keys the rules
     * name, which would strip every card field — `color`, `title`, `fields` and
     * the rest are shaped by the parser, not enumerated here, for the reason
     * given on {@see rules()}. What guarantees safety is the parser's rebuild,
     * not the shape of this array.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->all();
    }
}
