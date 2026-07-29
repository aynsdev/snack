<?php

namespace App\Http\Requests\Workspaces;

use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Workspace::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],

            // A caller may propose a slug, but SlugGenerator still normalizes
            // it and resolves collisions, so this only rejects obvious junk.
            'slug' => [
                'nullable',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('snack.workspaces.reserved_slugs', [])),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The workspace URL may only contain lowercase letters, numbers, and hyphens.',
            'slug.not_in' => 'That workspace URL is reserved.',
        ];
    }

    /**
     * The validated input, narrowed to the shape CreateWorkspace expects.
     *
     * `validated()` can only promise `array<string, mixed>`; stating the shape
     * here is what lets the action declare precise parameter types instead of
     * re-checking them.
     *
     * @return array{name: string, description: string|null, slug: string|null}
     */
    public function workspaceAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'description' => $this->filled('description')
                ? $this->string('description')->toString()
                : null,
            'slug' => $this->filled('slug')
                ? $this->string('slug')->toString()
                : null,
        ];
    }
}
