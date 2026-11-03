<?php

namespace App\Http\Requests\Standups;

use App\Models\Standup;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStandupRequest extends FormRequest
{
    use ValidatesStandupSchedule;

    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [Standup::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:80'],

            /*
             * The channel is validated as belonging to this workspace rather
             * than trusted from the select that produced it. It decides two
             * things at once — who gets direct-messaged every morning, and where
             * their answers are published — so a request naming another
             * workspace's channel must fail here, not reach an action that would
             * happily DM a roster the requester cannot see.
             *
             * Archived channels are refused: nobody is reading them, which makes
             * a standup that posts into one a private mailing list with extra
             * steps.
             */
            'channel_id' => [
                'required',
                'integer',
                Rule::exists('channels', 'id')
                    ->where('workspace_id', $context->workspace()->id)
                    /*
                     * The archived test goes through a closure rather than a
                     * second `->where('is_archived', false)`.
                     *
                     * An exists rule renders itself to a string, and a `false`
                     * constraint renders as the empty string — which Postgres
                     * refuses outright as input for a boolean column, so the
                     * rule 500s instead of failing validation. The closure form
                     * keeps a real query builder, where the boolean stays a
                     * boolean.
                     */
                    ->where(fn ($query) => $query->where('is_archived', false)),
            ],

            ...$this->scheduleRules(),
            ...$this->participantRules($context->workspace()->id),
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
