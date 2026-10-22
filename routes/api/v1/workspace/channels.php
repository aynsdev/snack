<?php

use App\Http\Controllers\Api\V1\Channels\ChannelArchiveController;
use App\Http\Controllers\Api\V1\Channels\ChannelController;
use App\Http\Controllers\Api\V1\Channels\ChannelGroupController;
use App\Http\Controllers\Api\V1\Channels\ChannelMemberController;
use App\Http\Controllers\Api\V1\Channels\ChannelMembershipController;
use App\Http\Controllers\Api\V1\Channels\ChannelPreferenceController;
use App\Http\Controllers\Channels\ChannelReadController;
use App\Http\Controllers\Channels\HuddleController as ChannelHuddleController;
use App\Http\Controllers\Channels\MentionableController;
use App\Http\Controllers\Channels\MessageController as ChannelMessageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Channels
|--------------------------------------------------------------------------
|
| Mirrors the web app's channel routes. Endpoints whose web controller already
| answers with JSON — history, posting, the read pointer, huddles, the mention
| picker — point straight at it, so there is one implementation of each.
|
*/

Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');
Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');
Route::get('channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
Route::patch('channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');

Route::post('channels/{channel}/archive', [ChannelArchiveController::class, 'store'])
    ->name('channels.archive.store');
Route::delete('channels/{channel}/archive', [ChannelArchiveController::class, 'destroy'])
    ->name('channels.archive.destroy');

Route::post('channels/{channel}/membership', [ChannelMembershipController::class, 'store'])
    ->name('channels.membership.store');
Route::delete('channels/{channel}/membership', [ChannelMembershipController::class, 'destroy'])
    ->name('channels.membership.destroy');

// The roster: the web channel page's deferred `members` prop.
Route::get('channels/{channel}/members', [ChannelController::class, 'members'])
    ->middleware('throttle:message-reads')
    ->name('channels.members.index');
Route::post('channels/{channel}/members', [ChannelMemberController::class, 'store'])
    ->name('channels.members.store');
Route::delete('channels/{channel}/members/{member}', [ChannelMemberController::class, 'destroy'])
    ->name('channels.members.destroy');

Route::patch('channels/{channel}/preferences', [ChannelPreferenceController::class, 'update'])
    ->name('channels.preferences.update');
Route::patch('channels/{channel}/group', [ChannelGroupController::class, 'assign'])
    ->name('channels.group.update');

Route::get('channels/{channel}/messages', [ChannelMessageController::class, 'index'])
    ->middleware('throttle:message-reads')
    ->name('channels.messages.index');
Route::post('channels/{channel}/messages', [ChannelMessageController::class, 'store'])
    ->middleware('throttle:messages')
    ->name('channels.messages.store');

Route::post('channels/{channel}/read', [ChannelReadController::class, 'store'])
    ->middleware('throttle:message-reads')
    ->name('channels.read.store');

// Start-or-join is throttled; hanging up deliberately is not (see web.php).
Route::post('channels/{channel}/huddle', [ChannelHuddleController::class, 'store'])
    ->middleware('throttle:huddles')
    ->name('channels.huddle.store');
Route::delete('channels/{channel}/huddle', [ChannelHuddleController::class, 'destroy'])
    ->name('channels.huddle.destroy');

Route::get('channels/{channel}/mentionable', [MentionableController::class, 'index'])
    ->middleware('throttle:message-reads')
    ->name('channels.mentionable.index');

/*
 * Sidebar sections, private to the member. Their own path rather than
 * `channels/groups`, which {channel} would swallow.
 */
Route::post('channel-groups', [ChannelGroupController::class, 'store'])
    ->name('channel-groups.store');
Route::patch('channel-groups/{channelGroup}', [ChannelGroupController::class, 'update'])
    ->name('channel-groups.update');
Route::post('channel-groups/{channelGroup}/move', [ChannelGroupController::class, 'move'])
    ->name('channel-groups.move');
Route::delete('channel-groups/{channelGroup}', [ChannelGroupController::class, 'destroy'])
    ->name('channel-groups.destroy');
