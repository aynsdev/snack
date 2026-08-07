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

        if (! is_string($payload)) {
            return;
        }

        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            $this->replace($decoded);
        }
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
        ];
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
