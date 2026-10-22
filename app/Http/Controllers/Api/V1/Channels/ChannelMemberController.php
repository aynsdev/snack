<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Channels\AddChannelMembers;
use App\Actions\Channels\RemoveChannelMember;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\StoreChannelMemberRequest;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Adding other people to a channel and removing them — the admin-gated mirror
 * of {@see ChannelMembershipController}. Same rules and same audit trail as
 * {@see \App\Http\Controllers\Channels\ChannelMemberController}; where the web
 * flashes an error, this answers 422.
 */
class ChannelMemberController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * Answers with the people actually added — anyone already in is skipped.
     */
    public function store(
        Workspace $workspace,
        Channel $channel,
        StoreChannelMemberRequest $request,
        AddChannelMembers $addMembers,
    ): JsonResponse {
        $added = $addMembers->handle(
            channel: $channel,
            actor: $request->user(),
            users: $request->nominees(),
        );

        if ($added->isEmpty()) {
            throw ValidationException::withMessages([
                'user_ids' => "Everyone you chose is already in #{$channel->slug}.",
            ]);
        }

        foreach ($added as $user) {
            $this->record($workspace, $channel, $request->user(), $user, AuditAction::ChannelMemberAdded, 'added_by');
        }

        return response()->json([
            'data' => $added->map(fn (User $user): array => (new UserResource($user))->resolve($request))->values()->all(),
        ], 201);
    }

    public function destroy(
        Workspace $workspace,
        Channel $channel,
        ChannelMember $member,
        Request $request,
        RemoveChannelMember $removeMember,
    ): Response {
        $this->authorize('manageMembers', $channel);

        if ($channel->is_default) {
            throw ValidationException::withMessages(['member' => 'Members cannot be removed from the default channel.']);
        }

        if ($member->user_id === $request->user()->id) {
            throw ValidationException::withMessages(['member' => 'Use Leave channel to remove yourself.']);
        }

        $subject = $member->user;

        $removeMember->handle($channel, $request->user(), $subject);
        $this->record($workspace, $channel, $request->user(), $subject, AuditAction::ChannelMemberRemoved, 'removed_by');

        return response()->noContent();
    }

    /**
     * One entry per person, hung off the channel, naming who did it — the
     * same shape the web controller writes.
     */
    private function record(
        Workspace $workspace,
        Channel $channel,
        User $actor,
        User $subject,
        AuditAction $action,
        string $actorKey,
    ): void {
        $values = [
            'channel' => $channel->slug,
            'user_id' => $subject->id,
            'username' => $subject->username,
            $actorKey => $actor->username,
        ];

        $this->audit->handle(
            workspace: $workspace,
            action: $action,
            actor: $actor,
            auditable: $channel,
            oldValues: $action === AuditAction::ChannelMemberRemoved ? $values : [],
            newValues: $action === AuditAction::ChannelMemberAdded ? $values : [],
        );
    }
}
