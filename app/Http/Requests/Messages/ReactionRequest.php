<?php

namespace App\Http\Requests\Messages;

use App\Models\Message;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adding or removing a reaction.
 *
 * The emoji is validated against the configured set rather than merely against
 * the column's length. A free-text shortcode would let a crafted request store
 * anything it liked, and every unrecognized value would render as a blank pill
 * that nobody could identify or remove.
 *
 * Authorized as `react` on the message, which {@see App\Policies\MessagePolicy}
 * resolves to posting rights on the container — reacting is a contribution to a
 * conversation, not merely reading one — and which refuses system messages.
 */
class ReactionRequest extends FormRequest
{
    /**
     * Returns the policy's own Response rather than a bool, so a private
     * channel's `denyAsNotFound()` stays a 404 instead of collapsing into a
     * 403 that confirms the message exists.
     */
    public function authorize(): Response
    {
        return Gate::inspect('react', $this->message());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emoji' => [
                'required',
                'string',
                Rule::in(array_keys((array) config('snack.reactions.available'))),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'emoji.in' => 'That is not a reaction you can use.',
        ];
    }

    public function message(): Message
    {
        $message = $this->route('message');

        abort_unless($message instanceof Message, 404);

        return $message;
    }

    public function emoji(): string
    {
        return $this->string('emoji')->toString();
    }
}
