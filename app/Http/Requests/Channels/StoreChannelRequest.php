<?php

namespace App\Http\Requests\Channels;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChannelRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [Channel::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        $role = $context->role();

        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:'.config('snack.channels.name_max_length'),
                Rule::notIn(config('snack.channels.reserved_names', [])),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'topic' => ['nullable', 'string', 'max:250'],

            // Announcement channels silence everyone but admins, so only an
            // admin may create one. The client cannot bypass this by posting a
            // different type.
            'type' => [
                'required',
                Rule::enum(ChannelType::class)->only(
                    $role->canAdministerWorkspace()
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
            'type.Illuminate\Validation\Rules\Enum' => 'Only workspace admins can create announcement channels.',
        ];
    }

    public function channelType(): ChannelType
    {
        return ChannelType::from($this->string('type')->toString());
    }
}
