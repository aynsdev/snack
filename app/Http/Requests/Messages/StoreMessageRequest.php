<?php

namespace App\Http\Requests\Messages;

use App\Http\Requests\Concerns\ResolvesAttachments;
use App\Http\Requests\Concerns\ResolvesThreadParent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
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
    use ResolvesAttachments, ResolvesThreadParent;

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
                /*
                 * A message with files and no words is a normal thing to send —
                 * "here is the deck" is the attachment. What is still rejected
                 * is a message that is empty in both senses.
                 */
                'required_without:attachment_ids',
                'nullable',
                'string',
                // Guards the column and the broadcast payload. The composer
                // enforces the same ceiling, but only as a courtesy.
                'max:'.config('snack.messages.max_length'),
            ],

            'parent_message_id' => $this->parentRules(),

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

    /**
     * The message being replied to, resolved through the channel itself.
     */
    public function parentMessage(): ?Message
    {
        return $this->resolveParent($this->channel());
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
