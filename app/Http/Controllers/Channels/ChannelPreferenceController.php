<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\UpdateChannelPreferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\UpdateChannelPreferencesRequest;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * One member's own notification settings for a channel.
 *
 * The mirror of {@see App\Http\Controllers\Conversations\ConversationPreferenceController},
 * and separate from the channel's shared settings for the same reason: renaming
 * a channel changes what everyone sees and answers to the channel admin, while
 * muting is private and available to anyone who can read the channel at all.
 */
class ChannelPreferenceController extends Controller
{
    public function update(
        Workspace $workspace,
        Channel $channel,
        UpdateChannelPreferencesRequest $request,
        UpdateChannelPreferences $updatePreferences,
    ): RedirectResponse {
        $membership = $updatePreferences->handle(
            channel: $channel,
            user: $request->user(),
            isMuted: $request->isMuted(),
            level: $request->notificationLevel(),
        );

        if ($membership === null) {
            return back()->with('error', 'Join this channel before changing its notifications.');
        }

        return back()->with(
            'success',
            $membership->is_muted
                ? "#{$channel->slug} muted."
                : "Notifications updated for #{$channel->slug}.",
        );
    }
}
