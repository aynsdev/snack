<?php

namespace App\Http\Requests\Channels;

use App\Enums\NotificationLevel;
use App\Models\Channel;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Muting a channel or changing its notification level.
 *
 * Gated on `view` rather than `post`, matching
 * {@see App\Http\Requests\Conversations\UpdateConversationPreferencesRequest}:
 * these are the viewer's own settings, and someone who can read an announcement
 * channel they may not post in still has a legitimate reason to silence it.
 *
 * Both fields are optional so a request that only mutes does not reset the
 * notification level as a side effect.
 */
class UpdateChannelPreferencesRequest extends FormRequest
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
            'is_muted' => ['sometimes', 'boolean'],
            'notification_level' => ['sometimes', Rule::enum(NotificationLevel::class)],
        ];
    }

    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_unless($channel instanceof Channel, 404);

        return $channel;
    }

    public function isMuted(): ?bool
    {
        return $this->has('is_muted') ? $this->boolean('is_muted') : null;
    }

    public function notificationLevel(): ?NotificationLevel
    {
        $level = $this->input('notification_level');

        return is_string($level) ? NotificationLevel::from($level) : null;
    }
}
