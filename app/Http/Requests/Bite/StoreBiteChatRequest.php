<?php

namespace App\Http\Requests\Bite;

use App\Enums\MagnificModel;
use App\Http\Requests\Concerns\ResolvesAttachments;
use App\Models\MessageAttachment;
use App\Models\Workspace;
use App\Services\BiteSummons;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The first message of a new Bite chat.
 *
 * The same rules as any message in a conversation — see
 * StoreConversationMessageRequest — because that is what it becomes. There is
 * no conversation yet to authorize against, so the gate is whether this person
 * may use Bite at all.
 */
class StoreBiteChatRequest extends FormRequest
{
    use ResolvesAttachments;

    public function authorize(): bool
    {
        return app(BiteSummons::class)->availableTo($this->workspace(), $this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => [
                'required_without:attachment_ids',
                'nullable',
                'string',
                'max:'.config('snack.messages.max_length'),
            ],

            'bite_model' => ['nullable', Rule::enum(MagnificModel::class)],

            ...$this->attachmentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Ask Bite something to start a chat.',
            'body.max' => 'That message is too long to send.',
        ];
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
