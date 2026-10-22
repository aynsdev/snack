<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Actions\Workspaces\UpdateNotificationPreferences;
use App\Enums\NotificationLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\ConversationResource;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member's own notification settings for this workspace, plus the channels
 * and conversations they have overridden — the mirror of
 * {@see \App\Http\Controllers\Notifications\NotificationPreferenceController}.
 * A save answers with the same payload.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function edit(Workspace $workspace, Request $request): JsonResponse
    {
        return $this->preferences($workspace, $request);
    }

    public function update(
        Workspace $workspace,
        UpdateNotificationPreferencesRequest $request,
        UpdateNotificationPreferences $updatePreferences,
    ): JsonResponse {
        $updatePreferences->handle(
            membership: $this->context->membership(),
            defaultLevel: $request->defaultLevel(),
            emailEnabled: $request->boolean('email_enabled'),
            digestEnabled: $request->boolean('digest_enabled'),
            doNotDisturb: $request->doNotDisturb(),
            pushEnabled: $request->pushEnabled(),
        );

        return $this->preferences($workspace, $request);
    }

    private function preferences(Workspace $workspace, Request $request): JsonResponse
    {
        $membership = $this->context->membership()->refresh();
        $user = $request->user();
        $default = $membership->defaultNotificationLevel();

        // Only the channels moved off the workspace default or muted.
        $channels = $workspace->channels()
            ->notArchived()
            ->whereMember($user)
            ->with(['members' => fn ($query) => $query->where('user_id', $user->id)])
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id)
                ->where(fn ($member) => $member
                    ->where('is_muted', true)
                    ->orWhere('notification_level', '!=', $default->value)))
            ->orderBy('name')
            ->get();

        // A DM starts at "everything", so anything below that is an override.
        $conversations = $workspace->conversations()
            ->visibleTo($user)
            ->with([
                'activeUsers',
                'participants' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id)
                ->where(fn ($participant) => $participant
                    ->where('is_muted', true)
                    ->orWhere('notification_level', '!=', NotificationLevel::All->value)))
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->get();

        return response()->json([
            'data' => [
                'preferences' => $membership->notificationSettings(),
                'levels' => array_map(
                    fn (NotificationLevel $level): array => ['value' => $level->value, 'label' => $level->label()],
                    NotificationLevel::cases(),
                ),
                'channels' => ChannelResource::collection($channels)->resolve($request),
                'conversations' => ConversationResource::collection($conversations)->resolve($request),
            ],
        ]);
    }
}
