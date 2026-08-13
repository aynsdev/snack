<?php

namespace App\Http\Requests\Bite;

use App\Models\BiteSetting;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing down a fact for Bite.
 *
 * The length limit is the same one {@see App\Ai\BiteKnowledge} enforces when it
 * assembles the prompt, and it is here as well as there on purpose: an admin who
 * pastes a wiki page in should be told at the moment they do it, rather than
 * having it silently truncated on every answer for the next six months.
 */
class StoreBiteMemoryRequest extends FormRequest
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
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'body' => [
                'required',
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
     * @return array{title: string, body: string, is_enabled: bool}
     */
    public function memory(): array
    {
        $validated = $this->validated();

        return [
            'title' => trim($validated['title']),
            'body' => trim($validated['body']),
            'is_enabled' => (bool) ($validated['is_enabled'] ?? true),
        ];
    }
}
