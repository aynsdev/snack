<?php

use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\ChannelMembershipController;
use App\Http\Controllers\Channels\ChannelReadController;
use App\Http\Controllers\Channels\MessageController as ChannelMessageController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\Workspaces\InvitationController;
use App\Http\Controllers\Workspaces\MemberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::inertia('/', 'welcome')->name('home');

/*
|--------------------------------------------------------------------------
| Invitations
|--------------------------------------------------------------------------
|
| Deliberately outside the auth middleware: the token is the credential, and an
| invited person needs to see what they were invited to before deciding whether
| to sign in or create an account. Accepting still requires authentication.
|
*/

Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.show');

Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept');

/*
|--------------------------------------------------------------------------
| Workspace selection
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    // The starter kit's dashboard entry point now lands people in a workspace.
    Route::redirect('dashboard', '/workspaces')->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Inside a workspace
|--------------------------------------------------------------------------
|
| The "workspace" middleware resolves {workspace} from its slug and rejects
| anyone who is not an active member, so everything below can assume a verified
| tenant context.
|
| scopeBindings() ties {channel} to the workspace's own channels, which matters
| because channel slugs are only unique per workspace — without it a member of
| one workspace could address another workspace's channel.
|
*/

Route::middleware(['auth', 'verified', 'workspace'])
    ->prefix('w/{workspace}')
    ->scopeBindings()
    ->group(function () {
        Route::get('/', [WorkspaceController::class, 'show'])->name('workspaces.show');

        // Channels
        Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::get('channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');

        Route::post('channels/{channel}/membership', [ChannelMembershipController::class, 'store'])
            ->name('channels.membership.store');
        Route::delete('channels/{channel}/membership', [ChannelMembershipController::class, 'destroy'])
            ->name('channels.membership.destroy');

        /*
         * Messages.
         *
         * These answer with JSON, not Inertia responses: the timeline is a
         * live client-owned list that merges paginated history, WebSocket
         * arrivals, and optimistic sends. See Channels\MessageController.
         */
        Route::get('channels/{channel}/messages', [ChannelMessageController::class, 'index'])
            ->middleware('throttle:message-reads')
            ->name('channels.messages.index');
        Route::post('channels/{channel}/messages', [ChannelMessageController::class, 'store'])
            ->middleware('throttle:messages')
            ->name('channels.messages.store');

        Route::post('channels/{channel}/read', [ChannelReadController::class, 'store'])
            ->middleware('throttle:message-reads')
            ->name('channels.read.store');

        // Edits and deletes address the message directly — a message id already
        // names its container, and a route carrying both invites a request that
        // names one channel and a message from another.
        Route::patch('messages/{message}', [MessageController::class, 'update'])
            ->middleware('throttle:messages')
            ->name('messages.update');
        Route::delete('messages/{message}', [MessageController::class, 'destroy'])
            ->name('messages.destroy');

        // Members
        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

        // Invitations
        Route::get('invitations', [InvitationController::class, 'index'])->name('workspace-invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('workspace-invitations.store');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->name('workspace-invitations.destroy');
    });

require __DIR__.'/settings.php';
