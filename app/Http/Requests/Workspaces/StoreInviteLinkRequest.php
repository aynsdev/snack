<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\WorkspaceRole;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or regenerating the workspace's invite link.
 *
 * Gated on the same permission as an emailed invitation, because issuing a link
 * *is* inviting — and a weaker gate here would make the link the way to get
 * around the stronger one.
 */
class StoreInviteLinkRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('inviteMembers', $context->workspace()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /*
             * Admin and member only. A guest joins no channels automatically,
             * so one arriving through a shared URL would land in an empty
             * workspace with nobody expecting them — see
             * {@see App\Actions\Workspaces\CreateWorkspaceInviteLink}.
             */
            'role' => [
                'sometimes',
                Rule::enum(WorkspaceRole::class)->only([
                    WorkspaceRole::Member,
                    WorkspaceRole::Admin,
                ]),
            ],

            // Absent means unlimited within the expiry window, which is what an
            // invite link usually is.
            'max_uses' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.(int) config('snack.invitations.link_max_uses', 1000),
            ],
        ];
    }

    /**
     * Defaults to Member: the role somebody gets for following a link should be
     * the least surprising one, and an admin who wants to hand out admin has to
     * say so deliberately.
     */
    public function role(): WorkspaceRole
    {
        return WorkspaceRole::tryFrom((string) $this->string('role'))
            ?? WorkspaceRole::Member;
    }

    public function maxUses(): ?int
    {
        $value = $this->input('max_uses');

        return $value === null || $value === '' ? null : (int) $value;
    }
}
