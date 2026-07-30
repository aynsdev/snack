<?php

namespace App\Http\Requests\Conversations;

use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Advancing the read pointer in a conversation.
 *
 * The submitted message id is a client-supplied identifier, so it is verified to
 * belong to *this* conversation before it is written. Without that check a
 * crafted request could park a pointer on an id from a conversation the user
 * cannot see — which is not a data leak on its own, but would corrupt the unread
 * arithmetic for the rest of the participant row's life.
 */
class MarkConversationReadRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('view', $this->conversation());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'last_read_message_id' => [
                'nullable',
                'integer',
                Rule::exists('messages', 'id')->where(
                    fn ($query) => $query->where('conversation_id', $this->conversation()->id),
                ),
            ],
        ];
    }

    public function conversation(): Conversation
    {
        $conversation = $this->route('conversation');

        abort_unless($conversation instanceof Conversation, 404);

        return $conversation;
    }

    /**
     * Null means "everything currently in the conversation".
     */
    public function lastReadMessageId(): ?int
    {
        $id = $this->input('last_read_message_id');

        return $id === null ? null : (int) $id;
    }
}
