<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\LeaveChannel;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Joining and leaving a channel under one's own steam.
 *
 * Audited here rather than in the actions, for the reason
 * {@see \App\Http\Controllers\Channels\ChannelMembershipController} sets out:
 * the actions are also the path for automatic joins.
 */
class ChannelMembershipController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function store(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        JoinChannel $joinChannel,
    ): JsonResponse {
        $this->authorize('join', $channel);

        $joinChannel->handle($channel, $request->user());

        $this->record($workspace, $channel, $request->user(), AuditAction::ChannelMemberAdded);

        $channel->loadCount('members');
        $channel->load(['members' => fn ($query) => $query->where('user_id', $request->user()->id)]);

        return (new ChannelResource($channel))->response();
    }

    public function destroy(
        Workspace $workspace,
        Channel $channel,
        Request $request,
        LeaveChannel $leaveChannel,
    ): Response {
        $this->authorize('leave', $channel);

        $leaveChannel->handle($channel, $request->user());

        $this->record($workspace, $channel, $request->user(), AuditAction::ChannelMemberRemoved);

        return response()->noContent();
    }

    private function record(
        Workspace $workspace,
        Channel $channel,
        User $user,
        AuditAction $action,
    ): void {
        $this->audit->handle(
            workspace: $workspace,
            action: $action,
            actor: $user,
            auditable: $channel,
            newValues: [
                'channel' => $channel->slug,
                'user_id' => $user->id,
                'username' => $user->username,
            ],
        );
    }
}
