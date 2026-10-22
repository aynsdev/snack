<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Actions\Channels\UpdateChannelPreferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\UpdateChannelPreferencesRequest;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * One member's own mute flag and notification level for a channel — private,
 * unlike the channel's shared settings. Answers with the channel as the viewer
 * now sees it.
 */
class ChannelPreferenceController extends Controller
{
    public function update(
        Workspace $workspace,
        Channel $channel,
        UpdateChannelPreferencesRequest $request,
        UpdateChannelPreferences $updatePreferences,
    ): ChannelResource {
        $membership = $updatePreferences->handle(
            channel: $channel,
            user: $request->user(),
            isMuted: $request->isMuted(),
            level: $request->notificationLevel(),
        );

        if ($membership === null) {
            throw ValidationException::withMessages([
                'channel' => 'Join this channel before changing its notifications.',
            ]);
        }

        return new ChannelResource($channel->setRelation('members', collect([$membership])));
    }
}
