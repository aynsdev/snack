<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Broadcast Channel Authorization
|--------------------------------------------------------------------------
|
| A WebSocket subscription is a read grant that lives for the whole session, so
| these callbacks are held to the same standard as the HTTP policies — and in
| fact delegate to them, so there is exactly one definition of "can this person
| see this channel?".
|
| Every id arriving here comes from the client and is therefore untrusted: each
| callback re-loads the record and re-authorizes it server-side.
|
*/

/**
 * Notifications addressed to a single user (Laravel's default convention).
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return $user->id === (int) $id;
});

/**
 * Per-user side channel for read-state sync, badge counts, and cross-device
 * events. Only ever the user themselves.
 */
Broadcast::channel('user.{id}', function (User $user, string $id): bool {
    return $user->id === (int) $id;
});

/**
 * Workspace-wide fan-out: channel created/updated/archived, member joined/left,
 * and workspace settings changes.
 *
 * A presence channel, so the workspace can render a live "who is online" roster
 * without polling. The returned payload is visible to every other subscriber,
 * so it deliberately carries only what the presence UI renders.
 */
Broadcast::channel('workspace.{workspaceId}', function (User $user, string $workspaceId): array|bool {
    $workspace = Workspace::find((int) $workspaceId);

    if ($workspace === null || ! $user->belongsToWorkspace($workspace)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'username' => $user->username,
        'avatar_url' => $user->avatarUrl(),
        'presence_status' => $user->presence_status->value,
        'status_emoji' => $user->currentStatusEmoji(),
    ];
}, ['guards' => ['web']]);

/**
 * A single channel's message stream, typing indicators, and reactions.
 *
 * Presence rather than private, so the member list can show who is currently
 * viewing. Authorization delegates to ChannelPolicy::view, which is what makes
 * a private channel unsubscribable by a non-member.
 */
Broadcast::channel('channel.{channelId}', function (User $user, string $channelId): array|bool {
    $channel = Channel::with('workspace')->find((int) $channelId);

    if ($channel === null || Gate::forUser($user)->denies('view', $channel)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'username' => $user->username,
        'avatar_url' => $user->avatarUrl(),
    ];
}, ['guards' => ['web']]);

/**
 * A direct or group conversation's message stream and typing indicators.
 */
Broadcast::channel('conversation.{conversationId}', function (User $user, string $conversationId): array|bool {
    // Looked up by the opaque public id, which is the only conversation
    // identifier a client is ever given.
    $conversation = Conversation::with('workspace')->where('public_id', $conversationId)->first();

    if ($conversation === null || Gate::forUser($user)->denies('view', $conversation)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'username' => $user->username,
        'avatar_url' => $user->avatarUrl(),
    ];
}, ['guards' => ['web']]);

/**
 * A thread's replies. Access is inherited from the thread root's container, so
 * the parent message is loaded and its own policy consulted.
 */
Broadcast::channel('thread.{messageId}', function (User $user, string $messageId): bool {
    $message = Message::with(['channel.workspace', 'conversation.workspace'])->find((int) $messageId);

    return $message !== null && Gate::forUser($user)->allows('view', $message);
}, ['guards' => ['web']]);
