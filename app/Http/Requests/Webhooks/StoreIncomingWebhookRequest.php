<?php

namespace App\Http\Requests\Webhooks;

use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a hook.
 *
 * The channel is the only field here that matters for security, and it is the
 * one thing that can never be changed afterwards — see
 * {@see App\Actions\Webhooks\UpdateIncomingWebhook} for why a live URL must not
 * be re-aimed.
 */
class StoreIncomingWebhookRequest extends FormRequest
{
    use ValidatesWebhookIdentity;

    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [IncomingWebhook::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            /*
             * Validated as belonging to this workspace rather than trusted from
             * the select that produced it. This field decides where a permanent,
             * unauthenticated write capability points, so a request naming
             * another tenant's channel has to fail here — not reach an action
             * that would happily mint a URL into a room the requester cannot
             * see.
             *
             * Archived channels are refused for the reason a standup refuses
             * them: nobody is reading one, so a hook posting into it is a
             * write-only integration that will be reported as broken.
             */
            'channel_id' => [
                'required',
                'integer',
                Rule::exists('channels', 'id')
                    ->where('workspace_id', $context->workspace()->id)
                    /*
                     * A closure rather than a second `->where('is_archived',
                     * false)`, for the reason spelled out in
                     * StoreStandupRequest: an exists rule stringifies its
                     * bindings, `false` becomes the empty string, and Postgres
                     * rejects that for a boolean column with a 500 instead of a
                     * validation error.
                     */
                    ->where(fn ($query) => $query->where('is_archived', false)),
            ],

            ...$this->identityRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->identityMessages(),
            'channel_id.exists' => 'That channel is not part of this workspace, or has been archived.',
        ];
    }

    /**
     * The destination, as a resolved model.
     *
     * Re-read here rather than passed as an id, so the action is handed a
     * channel that has already been proven to belong to this workspace — the
     * same discipline every other write path in Snack follows.
     */
    public function channel(WorkspaceContext $context): Channel
    {
        return $context->workspace()
            ->channels()
            ->whereKey($this->integer('channel_id'))
            ->firstOrFail();
    }
}
