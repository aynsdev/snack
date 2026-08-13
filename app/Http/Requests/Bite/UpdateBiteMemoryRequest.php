<?php

namespace App\Http\Requests\Bite;

use App\Models\BiteSetting;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a fact, or switching one off.
 *
 * Every field is `sometimes`, because this endpoint serves two gestures that
 * look nothing alike to the person making them: rewriting an entry in the form,
 * and flicking its toggle in the list. Requiring the whole record for the second
 * would mean the toggle had to send back text nobody was editing — and would
 * overwrite whatever another admin had changed in the meantime.
 */
class UpdateBiteMemoryRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('update', [BiteSetting::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:1', 'max:120'],
            'body' => [
                'sometimes',
                'string',
                'min:1',
                'max:'.(int) config('snack.bite.knowledge.max_entry_characters', 2_000),
            ],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.max' => 'One fact is limited to :max characters. Split it up, or put the detail in a file Bite can read.',
        ];
    }

    /**
     * Only the fields that were actually sent, so a toggle does not blank a body.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = [];

        if (array_key_exists('title', $validated)) {
            $changes['title'] = trim((string) $validated['title']);
        }

        if (array_key_exists('body', $validated)) {
            $changes['body'] = trim((string) $validated['body']);
        }

        if (array_key_exists('is_enabled', $validated)) {
            $changes['is_enabled'] = (bool) $validated['is_enabled'];
        }

        return $changes;
    }
}
