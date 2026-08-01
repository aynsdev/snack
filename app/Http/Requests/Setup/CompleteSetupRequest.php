<?php

namespace App\Http\Requests\Setup;

use App\Concerns\PasswordValidationRules;
use App\Services\InstanceSetup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteSetupRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Setup is authorized by the installation being empty, not by an actor —
     * there is nobody to be, which is the whole point of the screen. The route
     * carries the same guard as middleware; this repeats it so the request
     * cannot be made safe-looking by a routing mistake.
     */
    public function authorize(): bool
    {
        return app(InstanceSetup::class)->isPending();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),

            'workspace_name' => ['required', 'string', 'min:2', 'max:80'],
            'workspace_description' => ['nullable', 'string', 'max:500'],

            // Optional: an admin who would rather invite people from inside the
            // workspace can finish setup without naming anyone.
            'invitations' => ['nullable', 'array', 'max:'.self::maxInvitations()],
            'invitations.*' => [
                'required',
                'string',
                'email',
                'max:255',
                'distinct',
                // The owner is already a member; inviting themselves would
                // create an invitation that can never be accepted. Both sides
                // are lowercased in prepareForValidation so this comparison —
                // and `distinct` above — cannot be defeated by casing.
                Rule::notIn([$this->string('admin_email')->lower()->toString()]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invitations.*.not_in' => 'You are already the owner, so there is no need to invite yourself.',
            'invitations.*.distinct' => 'That address is listed twice.',
            'workspace_name.required' => 'Your workspace needs a name.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'admin_name' => 'name',
            'admin_email' => 'email address',
            'workspace_name' => 'workspace name',
            'workspace_description' => 'workspace description',
        ];
    }

    /**
     * Blank rows are how the invite step renders an empty field, so they are
     * dropped before validation rather than reported as errors.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('admin_email')) {
            $this->merge(['admin_email' => $this->string('admin_email')->trim()->lower()->toString()]);
        }

        if (! $this->has('invitations')) {
            return;
        }

        $invitations = array_values(array_filter(
            array_map(
                fn (mixed $email): string => is_string($email) ? mb_strtolower(trim($email)) : '',
                (array) $this->input('invitations', []),
            ),
            fn (string $email): bool => $email !== '',
        ));

        $this->merge(['invitations' => $invitations]);
    }

    /**
     * @return array{name: string, email: string, password: string}
     */
    public function adminAttributes(): array
    {
        return [
            'name' => $this->string('admin_name')->toString(),
            'email' => $this->string('admin_email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }

    /**
     * @return array{name: string, description: string|null}
     */
    public function workspaceAttributes(): array
    {
        return [
            'name' => $this->string('workspace_name')->toString(),
            'description' => $this->filled('workspace_description')
                ? $this->string('workspace_description')->toString()
                : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function invitationEmails(): array
    {
        /** @var list<string> $emails */
        $emails = array_values((array) $this->validated('invitations', []));

        return $emails;
    }

    /**
     * Capped so the opening screen cannot be used to queue an unbounded mail
     * run. The rest of a team arrives through the invitations page.
     */
    public static function maxInvitations(): int
    {
        return 20;
    }
}
