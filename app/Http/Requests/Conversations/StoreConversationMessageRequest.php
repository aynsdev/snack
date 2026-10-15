<?php

namespace App\Http\Requests\Conversations;

use App\Enums\MagnificModel;
use App\Http\Requests\Concerns\ResolvesAttachments;
use App\Http\Requests\Concerns\ResolvesThreadParent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Posting a message to a conversation.
 *
 * The conversation comes from the resolved, workspace-scoped route binding,
 * never from the body, so there is no `conversation_id` field to tamper with.
 */
class StoreConversationMessageRequest extends FormRequest
{
    use ResolvesAttachments, ResolvesThreadParent;

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
            // Files alone are a message; empty in both senses is not. Same rule
            // as the channel composer's — see StoreMessageRequest.
            'body' => [
                'required_without:attachment_ids',
                'nullable',
                'string',
                'max:'.config('snack.messages.max_length'),
            ],
            'parent_message_id' => $this->parentRules(),

            'bite_model' => ['nullable', Rule::prohibitedIf(! $this->conversation()->type->isBite()), Rule::enum(MagnificModel::class)],

            ...$this->attachmentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'A message cannot be empty.',
            'body.max' => 'That message is too long to send.',
            'attachment_ids.max' => 'That is more files than one message can carry.',
        ];
    }

    public function conversation(): Conversation
    {
        $conversation = $this->route('conversation');

        abort_unless($conversation instanceof Conversation, 404);

        return $conversation;
    }

    public function biteModel(): ?MagnificModel
    {
        $model = $this->validated('bite_model');

        return is_string($model) ? MagnificModel::from($model) : null;
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

    /**
     * @return Collection<int, MessageAttachment>
     */
    public function attachments(): Collection
    {
        return $this->resolveAttachments($this->workspace());
    }

    private function workspace(): Workspace
    {
        $workspace = $this->route('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        return $workspace;
    }
}
