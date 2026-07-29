<?php

namespace App\Http\Requests\Messages;

use App\Models\Channel;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Posting a message to a channel.
 *
 * The channel comes from the resolved route binding, never from the body, so
 * there is no `channel_id` field to tamper with — and the workspace scoping on
 * the route means the binding itself cannot reach another tenant's channel.
 */
class StoreMessageRequest extends FormRequest
{
    /**
     * Returns the policy's own Response rather than a bool.
     *
     * ChannelPolicy answers "private channel you were not invited to" with
     * `denyAsNotFound()`, and collapsing that to `false` would turn it into a
     * 403 — which confirms the channel exists. Handing the Response back
     * preserves the 404.
     */
    public function authorize(): Response
    {
        return Gate::inspect('post', $this->channel());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => [
                'required',
                'string',
                // Guards the column and the broadcast payload. The composer
                // enforces the same ceiling, but only as a courtesy.
                'max:'.config('snack.messages.max_length'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'A message cannot be empty.',
            'body.max' => 'That message is too long to send.',
        ];
    }

    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_unless($channel instanceof Channel, 404);

        return $channel;
    }

    public function messageBody(): string
    {
        return $this->string('body')->toString();
    }
}
