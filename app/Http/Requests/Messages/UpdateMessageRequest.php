<?php

namespace App\Http\Requests\Messages;

use App\Models\Message;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Editing a message.
 *
 * Only the body is accepted. Sender, container, and timestamps are not
 * addressable by an edit — permitting them would let an author move their own
 * message into a channel they could not post to.
 */
class UpdateMessageRequest extends FormRequest
{
    /**
     * The policy's Response is returned intact so a message inside a container
     * the user cannot see stays a 404 rather than becoming a 403.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->targetMessage());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.config('snack.messages.max_length')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'A message cannot be empty. Delete it instead.',
        ];
    }

    public function targetMessage(): Message
    {
        $message = $this->route('message');

        abort_unless($message instanceof Message, 404);

        return $message;
    }

    public function messageBody(): string
    {
        return $this->string('body')->toString();
    }
}
