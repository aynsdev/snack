<?php

namespace App\Http\Controllers\Notifications;

use App\Actions\Workspaces\UpdateNotificationPreferences;
use App\Enums\NotificationLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\ConversationResource;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The member's own notification settings for one workspace.
 *
 * Workspace-scoped rather than under `/settings`, because that is what these
 * are: a person can want everything from a five-person client workspace and
 * mentions only from a six-hundred-person one, and a single global setting
 * cannot express that.
 *
 * The page also lists the per-channel and per-conversation overrides in one
 * place. Muting is reachable from each channel's own menu, but "which of my
 * forty channels did I silence?" is a question only a list can answer, and
 * without it a mute made months ago is effectively unfindable.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function edit(Workspace $workspace, Request $request): InertiaResponse
    {
        $membership = $this->context->membership();

        return Inertia::render('notifications/preferences', [
            'preferences' => $membership->notificationSettings(),

            'levels' => array_map(
                fn (NotificationLevel $level): array => [
                    'value' => $level->value,
                    'label' => $level->label(),
                ],
                NotificationLevel::cases(),
            ),

            'channels' => $this->overriddenChannels(
                $workspace,
                $request,
                $membership->defaultNotificationLevel(),
            ),

            'conversations' => $this->overriddenConversations($workspace, $request),
        ]);
    }

    /**
     * The channels this member has deliberately changed.
     *
     * The overrides, not every joined channel: a row apiece would bury the
     * handful that were actually touched under the forty that were not, and
     * anything still at its default is already described by the workspace
     * setting above it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function overriddenChannels(
        Workspace $workspace,
        Request $request,
        NotificationLevel $default,
    ): array {
        $user = $request->user();

        $channels = $workspace->channels()
            ->notArchived()
            ->whereMember($user)
            // Constrained to this user so ChannelResource reads their own mute
            // flag without a query per channel.
            ->with(['members' => fn ($query) => $query->where('user_id', $user->id)])
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id)
                ->where(fn ($member) => $member
                    ->where('is_muted', true)
                    ->orWhere('notification_level', '!=', $default->value)))
            ->orderBy('name')
            ->get();

        return ChannelResource::collection($channels)->resolve($request);
    }

    /**
     * The conversations this member has silenced or turned down.
     *
     * Compared against {@see NotificationLevel::All} rather than the workspace
     * default: a direct message is addressed to you by definition, so it starts
     * at "everything" however quiet the workspace default is, and anything below
     * that is an override worth showing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function overriddenConversations(Workspace $workspace, Request $request): array
    {
        $user = $request->user();

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

        return ConversationResource::collection($conversations)->resolve($request);
    }

    public function update(
        Workspace $workspace,
        UpdateNotificationPreferencesRequest $request,
        UpdateNotificationPreferences $updatePreferences,
    ): RedirectResponse {
        $updatePreferences->handle(
            membership: $this->context->membership(),
            defaultLevel: $request->defaultLevel(),
            emailEnabled: $request->boolean('email_enabled'),
            digestEnabled: $request->boolean('digest_enabled'),
            doNotDisturb: $request->doNotDisturb(),
            pushEnabled: $request->pushEnabled(),
        );

        return back()->with('success', 'Notification preferences saved.');
    }
}
