<?php

namespace App\Http\Requests\Conversations;

use App\Http\Requests\Concerns\ResolvesThreadParent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Posting a message to a conversation.
 *
 * The conversation comes from the resolved, workspace-scoped route binding,
 * never from the body, so there is no `conversation_id` field to tamper with.
 */
class StoreConversationMessageRequest extends FormRequest
{
    use ResolvesThreadParent;

    /**
     * Returns the policy's own Response rather than a bool.
     *
     * ConversationPolicy answers "not a participant" with `denyAsNotFound()`,
     * and collapsing that to `false` would turn it into a 403 — which confirms
     * the conversation exists and, by extension, that those particular people
     * are talking. Handing the Response back preserves the 404.
     */
    public function authorize(): Response
    {
        return Gate::inspect('post', $this->conversation());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.config('snack.messages.max_length')],
            'parent_message_id' => $this->parentRules(),
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

    public function conversation(): Conversation
    {
        $conversation = $this->route('conversation');

        abort_unless($conversation instanceof Conversation, 404);

        return $conversation;
    }

    public function messageBody(): string
    {
        return $this->string('body')->toString();
    }

    /**
     * The message being replied to, resolved through the conversation itself.
     */
    public function parentMessage(): ?Message
    {
        return $this->resolveParent($this->conversation());
    }
}
