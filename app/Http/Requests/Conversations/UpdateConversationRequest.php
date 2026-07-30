<?php

namespace App\Http\Requests\Conversations;

use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Renaming a group conversation.
 *
 * Only the name is addressable. Type, workspace, and participants are not
 * something an edit may touch: permitting `type` would turn a group into a
 * direct conversation whose participant hash no longer matches its uniqueness
 * index, and permitting `workspace_id` would move a thread between tenants.
 */
class UpdateConversationRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('rename', $this->conversation());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nullable on purpose: clearing the name returns the conversation to
            // being titled by whoever is in it.
            'name' => ['present', 'nullable', 'string', 'max:'.config('snack.channels.name_max_length')],
        ];
    }

    public function conversation(): Conversation
    {
        $conversation = $this->route('conversation');

        abort_unless($conversation instanceof Conversation, 404);

        return $conversation;
    }

    public function conversationName(): ?string
    {
        $name = $this->input('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
