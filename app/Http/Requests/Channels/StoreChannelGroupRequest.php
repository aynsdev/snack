<?php

namespace App\Http\Requests\Channels;

use App\Models\ChannelGroup;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChannelGroupRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [ChannelGroup::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            /*
             * Unique per person per workspace, case-insensitively — the column
             * is citext. Scoped to `user_id` as well: two people arranging their
             * own sidebars both want a "Team", and neither has any business
             * being told the other took the name.
             */
            'name' => [
                'required',
                'string',
                'min:1',
                'max:60',
                Rule::unique('channel_groups', 'name')
                    ->where('workspace_id', $context->workspace()->id)
                    ->where('user_id', $this->user()?->id),
            ],

            /*
             * The channel to file into the new section, optionally.
             *
             * Here rather than in a second request from the client because the
             * two halves are one intention: "put this channel somewhere new"
             * is how a section gets made in the first place. A client that
             * created the section and then filed the channel would have to
             * learn the new id from a payload that answers with `back()`, and
             * would leave an empty section behind whenever the second call
             * failed.
             *
             * Two `exists` rules because the constraint is two facts: the
             * channel is in this workspace, and the sender is in the channel.
             * A section can only hold rooms its owner is actually in, and only
             * ones from the workspace the section belongs to.
             */
            'channel_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('channels', 'id')
                    ->where('workspace_id', $context->workspace()->id),
                Rule::exists('channel_members', 'channel_id')
                    ->where('user_id', $this->user()?->id),
            ],
        ];
    }

    public function channelId(): ?int
    {
        $id = $this->input('channel_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a section with that name.',
        ];
    }
}
