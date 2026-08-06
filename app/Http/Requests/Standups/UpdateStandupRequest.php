<?php

namespace App\Http\Requests\Standups;

use App\Models\Standup;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStandupRequest extends FormRequest
{
    use ValidatesStandupSchedule;

    public function authorize(): bool
    {
        $standup = $this->route('standup');

        return $standup instanceof Standup
            && ($this->user()?->can('update', $standup) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:80'],

            // Moving a standup to another channel moves both halves of it: who
            // is asked and where it is published. Scoped to this workspace for
            // the same reason it is on the way in — see StoreStandupRequest.
            'channel_id' => [
                'required',
                'integer',
                Rule::exists('channels', 'id')
                    ->where('workspace_id', $context->workspace()->id)
                    // Closure rather than a plain `false` — see the note in
                    // StoreStandupRequest.
                    ->where(fn ($query) => $query->where('is_archived', false)),
            ],

            ...$this->scheduleRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->scheduleMessages(),
            'channel_id.exists' => 'That channel is not part of this workspace.',
        ];
    }
}
