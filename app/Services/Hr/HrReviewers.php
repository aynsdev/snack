<?php

namespace App\Services\Hr;

use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The people who decide HR requests in a workspace.
 */
final class HrReviewers
{
    /**
     * The workspace's active, human admins, other than `$except` — whoever
     * asked does not need telling about their own request.
     *
     * @return Collection<int, User>
     */
    public static function for(int $workspaceId, ?User $except = null): Collection
    {
        $roles = array_map(
            fn (WorkspaceRole $role): string => $role->value,
            array_filter(WorkspaceRole::cases(), fn (WorkspaceRole $role): bool => $role->canManagePeople()),
        );

        return User::query()
            ->where('is_bot', false)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->whereHas('workspaceMemberships', fn ($query) => $query
                ->where('workspace_id', $workspaceId)
                ->where('status', MemberStatus::Active)
                ->whereIn('role', $roles))
            ->get();
    }
}
