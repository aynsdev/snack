<?php

namespace App\Http\Requests\Channels;

use App\Enums\MemberStatus;
use App\Models\Channel;
use App\Models\User;
use App\Policies\ChannelPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adding other people to a channel.
 *
 * Two independent checks, and both are needed:
 *
 *  - the policy decides whether the *actor* administers this channel at all,
 *    and refuses an archived one, so a closed channel cannot quietly gain
 *    members;
 *  - the rules decide whether each *nominee* is an active member of this
 *    workspace, because a user id in the body is otherwise a way to put an
 *    outsider inside a private channel and hand them everything said in it from
 *    that moment on.
 *
 * Nothing here refuses a guest: being added by somebody else is the *only* way a
 * guest gets into a channel — {@see ChannelPolicy::join()} denies
 * them the self-serve path deliberately — so this is the endpoint that has to
 * allow it.
 */
class StoreChannelMemberRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manageMembers', $this->channel());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => [
                'integer',
                Rule::exists('workspace_members', 'user_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $this->channel()->workspace_id)
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
            'user_ids.required' => 'Choose at least one person to add.',
            'user_ids.*.exists' => 'That person is not a member of this workspace.',
        ];
    }

    public function channel(): Channel
    {
        $channel = $this->route('channel');

        abort_unless($channel instanceof Channel, 404);

        return $channel;
    }

    /**
     * The nominees, as models resolved through the workspace's own
     * active-membership relation, so both tenancy and suspension are part of the
     * query that loads them rather than checks a caller has to remember.
     *
     * @return Collection<int, User>
     */
    public function nominees(): Collection
    {
        $ids = $this->collect('user_ids')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->channel()->workspace->activeUsers()
            ->whereIn('users.id', $ids)
            ->get();
    }
}
