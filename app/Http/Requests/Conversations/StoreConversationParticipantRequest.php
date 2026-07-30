<?php

namespace App\Http\Requests\Conversations;

use App\Enums\MemberStatus;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adding people to a group conversation.
 *
 * Two independent checks, and both are needed:
 *
 *  - the policy decides whether the *actor* may add anyone here at all, and
 *    refuses outright for a direct message, whose uniqueness guarantee a third
 *    participant would quietly break;
 *  - the rules decide whether each *nominee* is an active member of this
 *    workspace, because a user id in the body is otherwise a way to pull an
 *    outsider into a thread and hand them its history.
 */
class StoreConversationParticipantRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('addParticipants', $this->conversation());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'participant_ids' => ['required', 'array', 'min:1', 'max:50'],
            'participant_ids.*' => [
                'integer',
                Rule::exists('workspace_members', 'user_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $this->conversation()->workspace_id)
                        ->where('status', MemberStatus::Active->value),
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
            'participant_ids.required' => 'Choose at least one person to add.',
            'participant_ids.*.exists' => 'That person is not a member of this workspace.',
        ];
    }

    public function conversation(): Conversation
    {
        $conversation = $this->route('conversation');

        abort_unless($conversation instanceof Conversation, 404);

        return $conversation;
    }

    /**
     * The nominees, as models resolved through the workspace's own
     * active-membership relation, so both tenancy and suspension are part of the
     * query that loads them rather than checks a caller has to remember.
     *
     * @return Collection<int, User>
     */
    public function participants(): Collection
    {
        $ids = $this->collect('participant_ids')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->conversation()->workspace->activeUsers()
            ->whereIn('users.id', $ids)
            ->get();
    }
}
