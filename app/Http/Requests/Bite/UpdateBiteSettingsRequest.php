<?php

namespace App\Http\Requests\Bite;

use App\Enums\BiteAudience;
use App\Enums\BiteCapability;
use App\Enums\BiteChannelMode;
use App\Models\BiteSetting;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBiteSettingsRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('update', [BiteSetting::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],

            'audience' => ['required', Rule::enum(BiteAudience::class)],

            'channel_mode' => ['required', Rule::enum(BiteChannelMode::class)],

            /*
             * The guard list. Every id is re-resolved against this workspace's
             * own channels, so a form that names another tenant's channel is
             * rejected rather than silently stored — the guard is read on a
             * queue worker later, where there is nothing left to check it
             * against.
             *
             * Archived channels are allowed through: a channel can be archived
             * after being listed, and refusing the whole save because of one
             * would leave an admin unable to change anything else on the page
             * until they worked out which entry had gone stale.
             */
            'channel_ids' => ['present', 'array', 'max:200'],
            'channel_ids.*' => [
                'integer',
                Rule::exists('channels', 'id')->where('workspace_id', $context->workspace()->id),
            ],

            'instructions' => [
                'nullable',
                'string',
                'max:'.(int) config('snack.bite.max_instruction_characters', 2_000),
            ],

            /*
             * Null means "use whatever the installation is configured for", so
             * both of these are nullable rather than defaulted here. The ranges
             * are the ones the feature actually works within: one message of
             * context is not a conversation, and a step ceiling of one is a
             * model that can search or answer but not both.
             */
            'context_messages' => ['nullable', 'integer', 'min:2', 'max:100'],
            'max_steps' => ['nullable', 'integer', 'min:2', 'max:16'],

            /*
             * The capabilities that stay switched on. `present` rather than
             * `required` because an empty array is a meaningful answer — an
             * assistant that may talk and read nothing — while a missing key
             * would silently mean "everything".
             */
            'capabilities' => ['present', 'array'],
            'capabilities.*' => [Rule::enum(BiteCapability::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channel_ids.*.exists' => 'One of those channels is not part of this workspace.',
            'instructions.max' => 'House instructions are limited to :max characters.',
            'context_messages.min' => 'Bite needs at least :min messages of context to follow a conversation.',
            'max_steps.min' => 'Bite needs at least :min steps to search and then answer.',
        ];
    }

    /**
     * The validated values, in the shape the model stores.
     *
     * The channel list is emptied of duplicates and re-indexed, because it goes
     * into a JSON column that is compared against on every summon and a list
     * with holes in its keys serializes as an object rather than an array.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $validated = $this->validated();

        return [
            'is_enabled' => (bool) $validated['is_enabled'],
            'audience' => BiteAudience::from($validated['audience']),
            'channel_mode' => BiteChannelMode::from($validated['channel_mode']),
            'channel_ids' => array_values(array_unique(array_map(
                fn ($id): int => (int) $id,
                $validated['channel_ids'] ?? [],
            ))),
            'instructions' => $this->trimmed($validated['instructions'] ?? null),
            'context_messages' => $validated['context_messages'] ?? null,
            'max_steps' => $validated['max_steps'] ?? null,
            'capabilities' => array_values(array_unique($validated['capabilities'] ?? [])),
        ];
    }

    /**
     * Whitespace-only instructions are no instructions.
     *
     * Stored as null rather than as a string of spaces, so that the prompt
     * builder's "is there anything here" check is a null test rather than a trim
     * on every answer.
     */
    private function trimmed(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
