<?php

namespace App\Http\Requests\Conversations;

use App\Enums\MemberStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Starting a direct or group conversation.
 *
 * # Every id here is hostile until proven otherwise
 *
 * `participant_ids` is the one place in the DM feature where the client names
 * *people* rather than a record the route already resolved. Left unchecked it
 * would be an invitation to open a conversation with any user id in the
 * database — someone from another customer's workspace — and then read
 * everything said in it.
 *
 * So each id has to exist as an **active membership of this workspace**, which
 * is a stricter test than "is a user" and stricter than "has a membership row":
 * a suspended member is treated exactly like an outsider everywhere else in
 * Snack, and this is no exception.
 */
class StoreConversationRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', [Conversation::class, $this->workspace()]);
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
                        ->where('workspace_id', $this->workspace()->id)
                        ->where('status', MemberStatus::Active->value),
                ),
            ],

            // Only meaningful for a group; the action drops it for a direct
            // conversation, as does a CHECK constraint on the table.
            'name' => ['nullable', 'string', 'max:'.config('snack.channels.name_max_length')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'participant_ids.required' => 'Choose at least one person to message.',
            'participant_ids.*.exists' => 'That person is not a member of this workspace.',
        ];
    }

    /**
     * Reject a conversation whose only participant is the person starting it.
     *
     * The creator is always added, so a payload of just their own id would
     * otherwise produce a one-person "direct" conversation — a row that no
     * screen in Snack knows how to title and that the uniqueness rules were
     * never designed for.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->participants()->isEmpty()) {
                    $validator->errors()->add(
                        'participant_ids',
                        'Choose someone other than yourself to message.',
                    );
                }
            },
        ];
    }

    public function workspace(): Workspace
    {
        $workspace = $this->route('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        return $workspace;
    }

    /**
     * The people to open the conversation with, as resolved models.
     *
     * Returning models rather than ids is what lets the action take verified
     * input only — there is no path from a raw request id into a participant row.
     *
     * @return Collection<int, User>
     */
    public function participants(): Collection
    {
        $ids = $this->collect('participant_ids')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $id === $this->user()->id)
            ->values()
            ->all();

        /*
         * Re-read through the workspace's own active-membership relation rather
         * than User::whereIn, so both tenancy *and* suspension are part of the
         * query that loads them. The validation rules already reject a
         * suspended id; this makes the action safe even if a caller ever
         * reaches it another way.
         */
        return $this->workspace()
            ->activeUsers()
            ->whereIn('users.id', $ids)
            ->get();
    }

    public function conversationName(): ?string
    {
        $name = $this->input('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
