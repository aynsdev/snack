<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\WorkspaceMember;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Handles both role changes and suspend/reinstate, since the admin UI presents
 * them as edits to the same row.
 */
class UpdateWorkspaceMemberRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        $member = $this->route('member');

        if (! $member instanceof WorkspaceMember) {
            return false;
        }

        return $this->user()?->can('manageMember', [$context->workspace(), $member]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(WorkspaceRole::class)->only(WorkspaceRole::assignable())],
            'status' => ['sometimes', Rule::enum(MemberStatus::class)],
        ];
    }

    public function newRole(): ?WorkspaceRole
    {
        return $this->has('role')
            ? WorkspaceRole::from($this->string('role')->toString())
            : null;
    }

    public function newStatus(): ?MemberStatus
    {
        return $this->has('status')
            ? MemberStatus::from($this->string('status')->toString())
            : null;
    }
}
