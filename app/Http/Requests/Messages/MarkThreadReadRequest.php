<?php

namespace App\Http\Requests\Messages;

use App\Models\Message;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Advancing the read pointer inside a thread.
 *
 * The submitted reply id is client-supplied, so it is verified to be a reply
 * *of this thread* before it is written. Without that check a crafted request
 * could park the pointer on an id from a thread the user cannot see — which
 * leaks nothing by itself, but would corrupt the unread arithmetic for the life
 * of the subscription, exactly as it would on a channel membership.
 */
class MarkThreadReadRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('view', $this->message());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'last_read_reply_id' => [
                'nullable',
                'integer',
                Rule::exists('messages', 'id')->where(
                    fn ($query) => $query->where('parent_message_id', $this->rootId()),
                ),
            ],
        ];
    }

    public function message(): Message
    {
        $message = $this->route('message');

        abort_unless($message instanceof Message, 404);

        return $message;
    }

    /**
     * Normalized to the root, so a request naming a reply still marks the
     * thread it belongs to.
     */
    public function rootId(): int
    {
        return $this->message()->threadRootId();
    }

    /**
     * Null means "every reply currently in the thread".
     */
    public function lastReadReplyId(): ?int
    {
        $id = $this->input('last_read_reply_id');

        return $id === null ? null : (int) $id;
    }
}
