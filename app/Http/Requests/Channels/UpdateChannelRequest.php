<?php

namespace App\Http\Requests\Channels;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Editing a channel's shared settings.
 *
 * Distinct from {@see UpdateChannelPreferencesRequest} in what it answers to:
 * this changes what everyone in the workspace sees and is gated on
 * ChannelPolicy::update, which admits workspace admins and the channel's own
 * admins. Muting is private and available to anyone who can read the channel.
 *
 * The policy also refuses an archived channel, so editing one is a single
 * denial rather than a rule each caller has to remember.
 */
class UpdateChannelRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->channel());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'min:1',
                'max:'.config('snack.channels.name_max_length'),
                Rule::notIn(config('snack.channels.reserved_names', [])),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:250'],

            /*
             * The same restriction StoreChannelRequest applies, for the same
             * reason: an announcement channel silences everyone but admins, so
             * a channel admin who is an ordinary workspace member must not be
             * able to convert their channel into one.
             */
            'type' => [
                'sometimes',
                Rule::enum(ChannelType::class)->only(
                    $context->role()->canAdministerWorkspace()
                        ? ChannelType::cases()
                        : [ChannelType::Public, ChannelType::Private],
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.not_in' => 'That channel name is reserved.',
            'type.Illuminate\Validation\Rules\Enum' => 'Only workspace admins can turn a channel into an announcement channel.',
        ];
    }

    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_unless($channel instanceof Channel, 404);

        return $channel;
    }

    /**
     * Only the fields actually submitted, so a form that edits the topic does
     * not blank the description by omitting it.
     *
     * @return array{name?: string, description?: string|null, topic?: string|null, type?: ChannelType}
     */
    public function channelAttributes(): array
    {
        $attributes = [];

        if ($this->has('name')) {
            $attributes['name'] = $this->string('name')->squish()->toString();
        }

        foreach (['description', 'topic'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $attributes[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        if ($this->has('type')) {
            $attributes['type'] = ChannelType::from($this->string('type')->toString());
        }

        return $attributes;
    }
}
