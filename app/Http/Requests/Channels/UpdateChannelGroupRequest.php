<?php

namespace App\Http\Requests\Channels;

use App\Models\ChannelGroup;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateChannelGroupRequest extends FormRequest
{
    /**
     * The policy's own response, not a boolean: somebody else's section answers
     * not-found rather than forbidden, and `can()` would flatten that to a 403
     * — which is itself the confirmation the policy is refusing to give.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->group());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $group = $this->group();

        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:60',
                Rule::unique('channel_groups', 'name')
                    ->where('workspace_id', $group->workspace_id)
                    ->where('user_id', $group->user_id)
                    ->ignore($group->id),
            ],
        ];
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

    private function group(): ChannelGroup
    {
        /** @var ChannelGroup $group */
        $group = $this->route('channelGroup');

        return $group;
    }
}
