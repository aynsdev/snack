<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Advancing the read pointer in a channel.
 *
 * The submitted message id is a client-supplied identifier, so it is verified
 * to belong to *this* channel before it is written. Without that check, a
 * crafted request could park a pointer on an id from a private channel the user
 * cannot see — which is not a data leak on its own, but would corrupt the
 * unread arithmetic for the rest of the membership's life.
 */
class MarkChannelReadRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('view', $this->channel());
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
                    fn ($query) => $query->where('channel_id', $this->channel()->id),
                ),
            ],
        ];
    }

    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_unless($channel instanceof Channel, 404);

        return $channel;
    }

    /**
     * Null means "everything currently in the channel".
     */
    public function lastReadMessageId(): ?int
    {
        $id = $this->input('last_read_message_id');

        return $id === null ? null : (int) $id;
    }
}
