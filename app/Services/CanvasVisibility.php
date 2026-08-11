<?php

namespace App\Services;

use App\Models\Canvas;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class CanvasVisibility
{
    /** @return Builder<Canvas> */
    public function query(Workspace $workspace, User $user): Builder
    {
        $role = $user->roleIn($workspace);

        if ($role === null || $role->isGuest()) {
            return Canvas::query()->whereRaw('1 = 0');
        }

        $visibleChannelIds = Channel::query()
            ->where('workspace_id', $workspace->id)
            ->visibleTo($user, $role)
            ->select('id');

        return Canvas::query()
            ->where('workspace_id', $workspace->id)
            ->where(function (Builder $visible) use ($user, $visibleChannelIds): void {
                $visible
                    ->where(function (Builder $workspaceCanvas): void {
                        $workspaceCanvas
                            ->whereNull('channel_id')
                            ->whereNull('conversation_id');
                    })
                    ->orWhereIn('channel_id', $visibleChannelIds)
                    ->orWhereHas(
                        'conversation.participants',
                        fn (Builder $participants) => $participants->where('user_id', $user->id),
                    );
            });
    }
}
