<?php

namespace App\Http\Requests\Conversations;

use App\Enums\NotificationLevel;
use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Muting a conversation or changing its notification level.
 *
 * Gated on `view` rather than `post`: these are the viewer's own settings, and
 * someone who has left a group still has a legitimate reason to silence it.
 * Both fields are optional so a request that only mutes does not reset the
 * notification level as a side effect.
 */
class UpdateConversationPreferencesRequest extends FormRequest
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
            'is_muted' => ['sometimes', 'boolean'],
            'notification_level' => ['sometimes', Rule::enum(NotificationLevel::class)],
        ];
    }

    public function conversation(): Conversation
    {
        $conversation = $this->route('conversation');

        abort_unless($conversation instanceof Conversation, 404);

        return $conversation;
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
