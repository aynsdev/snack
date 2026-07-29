<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\WorkspaceRole;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWorkspaceInvitationRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('inviteMembers', $context->workspace()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `rfc` without `dns`: an internal deployment routinely invites
            // addresses on domains that do not resolve publicly, and a live DNS
            // lookup on every invite would be both slow and flaky.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],

            // Ownership is never assignable through an invitation.
            'role' => ['required', Rule::enum(WorkspaceRole::class)->only(WorkspaceRole::assignable())],
        ];
    }

    /**
     * Reject invitations to people who are already here. This is a friendlier
     * failure than the database's partial unique index, which cannot express
     * "already a member".
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(WorkspaceContext $context): array
    {
        return [
            function (Validator $validator) use ($context): void {
                $email = $this->string('email')->lower()->toString();

                $alreadyMember = $context->workspace()
                    ->users()
                    ->whereRaw('lower(users.email::text) = ?', [$email])
                    ->exists();

                if ($alreadyMember) {
                    $validator->errors()->add('email', 'That person is already a member of this workspace.');
                }
            },
        ];
    }

    public function role(): WorkspaceRole
    {
        return WorkspaceRole::from($this->string('role')->toString());
    }
}
