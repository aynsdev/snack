<?php

namespace App\Http\Requests\Channels;

use App\Models\Channel;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Filing one channel under one of your headings, or under none.
 *
 * Gated on `view` rather than `post`, matching
 * {@see UpdateChannelPreferencesRequest}: where a channel sits in your sidebar
 * is your business, not the channel admin's, and someone who may only read an
 * announcement channel still arranges their own list.
 */
class AssignChannelGroupRequest extends FormRequest
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
            /*
             * `present` with `nullable`: the key must be in the payload, and its
             * value may be null. Null is the whole of "take it out of its
             * section" — ungrouped is not a second state reached by a second
             * endpoint, it is the absence of a section.
             *
             * Scoped to the sender's own groups in the channel's own workspace.
             * The database cannot express that constraint — `channel_group_id`
             * is a plain foreign key on the membership row — so this rule is
             * what stops an id lifted from someone else's sidebar from being
             * filed into it.
             */
            'channel_group_id' => [
                'present',
                'nullable',
                'integer',
                Rule::exists('channel_groups', 'id')
                    ->where('workspace_id', $this->channel()->workspace_id)
                    ->where('user_id', $this->user()?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channel_group_id.exists' => 'That section is not one of yours.',
        ];
    }

    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_unless($channel instanceof Channel, 404);

        return $channel;
    }

    public function channelGroupId(): ?int
    {
        $id = $this->input('channel_group_id');

        return $id === null ? null : (int) $id;
    }
}
