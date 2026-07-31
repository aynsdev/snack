<?php

use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\ChannelMembershipController;
use App\Http\Controllers\Channels\ChannelReadController;
use App\Http\Controllers\Channels\MentionableController;
use App\Http\Controllers\Channels\MessageController as ChannelMessageController;
use App\Http\Controllers\Conversations\ConversationController;
use App\Http\Controllers\Conversations\ConversationPreferenceController;
use App\Http\Controllers\Conversations\ConversationReadController;
use App\Http\Controllers\Conversations\DirectoryController;
use App\Http\Controllers\Conversations\MessageController as ConversationMessageController;
use App\Http\Controllers\Conversations\ParticipantController;
use App\Http\Controllers\Files\DownloadController;
use App\Http\Controllers\Files\FileBrowserController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\Messages\MentionController;
use App\Http\Controllers\Messages\PinController;
use App\Http\Controllers\Messages\ReactionController;
use App\Http\Controllers\Messages\SaveController;
use App\Http\Controllers\Messages\ThreadController;
use App\Http\Controllers\Messages\ThreadReadController;
use App\Http\Controllers\Messages\ThreadSubscriptionController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\UploadController;
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

        // Who the composer offers for an @-mention. Scoped to the channel,
        // because only members can actually be mentioned.
        Route::get('channels/{channel}/mentionable', [MentionableController::class, 'index'])
            ->middleware('throttle:message-reads')
            ->name('channels.mentionable.index');

        /*
         * Direct and group conversations.
         *
         * Bound by id rather than by a slug: a conversation has no name of its
         * own to slug — an unnamed DM is titled by whoever is in it, which is a
         * different string for each participant — and its id is meaningless to
         * anyone the scoped binding does not let in.
         *
         * "dms" rather than "conversations" so the URL a person sees is the word
         * they use for the thing.
         */
        Route::get('dms/directory', [DirectoryController::class, 'index'])
            ->middleware('throttle:message-reads')
            ->name('conversations.directory.index');

        Route::post('dms', [ConversationController::class, 'store'])
            ->middleware('throttle:messages')
            ->name('conversations.store');
        Route::get('dms/{conversation}', [ConversationController::class, 'show'])
            ->name('conversations.show');
        Route::patch('dms/{conversation}', [ConversationController::class, 'update'])
            ->name('conversations.update');

        Route::get('dms/{conversation}/messages', [ConversationMessageController::class, 'index'])
            ->middleware('throttle:message-reads')
            ->name('conversations.messages.index');
        Route::post('dms/{conversation}/messages', [ConversationMessageController::class, 'store'])
            ->middleware('throttle:messages')
            ->name('conversations.messages.store');

        Route::post('dms/{conversation}/read', [ConversationReadController::class, 'store'])
            ->middleware('throttle:message-reads')
            ->name('conversations.read.store');

        Route::post('dms/{conversation}/participants', [ParticipantController::class, 'store'])
            ->name('conversations.participants.store');
        Route::delete('dms/{conversation}/participants', [ParticipantController::class, 'destroy'])
            ->name('conversations.participants.destroy');

        Route::patch('dms/{conversation}/preferences', [ConversationPreferenceController::class, 'update'])
            ->name('conversations.preferences.update');

        // Edits and deletes address the message directly — a message id already
        // names its container, and a route carrying both invites a request that
        // names one channel and a message from another.
        Route::patch('messages/{message}', [MessageController::class, 'update'])
            ->middleware('throttle:messages')
            ->name('messages.update');
        Route::delete('messages/{message}', [MessageController::class, 'destroy'])
            ->name('messages.destroy');

        /*
         * Threads.
         *
         * Addressed by the message they hang off, for the same reason edits are:
         * the id already names its container. Replies are *posted* through the
         * container's own message endpoint with a `parent_message_id` rather
         * than here, so there is exactly one send path — see Messages\ThreadController.
         */
        Route::get('threads', [ThreadController::class, 'index'])->name('threads.index');
        Route::get('mentions', [MentionController::class, 'index'])->name('mentions.index');
        Route::get('saved', [SaveController::class, 'index'])->name('saved.index');

        /*
         * Search and the file browser.
         *
         * Both are Inertia pages rather than JSON endpoints: their state belongs
         * in the URL so a search can be linked and reloaded, and neither has
         * anything live about it to merge.
         *
         * These are the only two read paths in the app that start from the whole
         * workspace instead of from a container the route already authorized, so
         * both narrow through App\Services\MessageVisibility.
         */
        Route::get('search', [SearchController::class, 'index'])
            ->middleware('throttle:message-reads')
            ->name('search.index');

        Route::get('files', [FileBrowserController::class, 'index'])
            ->middleware('throttle:message-reads')
            ->name('files.index');

        /*
         * The composer's attachment tray. A file is uploaded while the composer
         * is still open and is only claimed by a message when that message is
         * sent — see App\Actions\Attachments\StoreUpload.
         */
        Route::post('uploads', [UploadController::class, 'store'])
            ->middleware('throttle:uploads')
            ->name('uploads.store');
        Route::delete('uploads/{attachment}', [UploadController::class, 'destroy'])
            ->name('uploads.destroy');

        Route::get('messages/{message}/thread', [ThreadController::class, 'show'])
            ->middleware('throttle:message-reads')
            ->name('threads.show');

        Route::post('messages/{message}/thread/read', [ThreadReadController::class, 'store'])
            ->middleware('throttle:message-reads')
            ->name('threads.read.store');

        Route::post('messages/{message}/thread/subscription', [ThreadSubscriptionController::class, 'store'])
            ->name('threads.subscription.store');
        Route::delete('messages/{message}/thread/subscription', [ThreadSubscriptionController::class, 'destroy'])
            ->name('threads.subscription.destroy');

        /*
         * Reactions. Two idempotent endpoints rather than one toggle — see
         * Messages\ReactionController for why a toggle is the wrong shape for
         * the one control people double-click.
         */
        /*
         * Pins are shared with the container; saves are private to the person
         * who made them. Same shape, deliberately different authorization —
         * see the two controllers.
         */
        Route::post('messages/{message}/pin', [PinController::class, 'store'])
            ->name('pins.store');
        Route::delete('messages/{message}/pin', [PinController::class, 'destroy'])
            ->name('pins.destroy');

        Route::post('messages/{message}/save', [SaveController::class, 'store'])
            ->name('saves.store');
        Route::delete('messages/{message}/save', [SaveController::class, 'destroy'])
            ->name('saves.destroy');

        Route::post('messages/{message}/reactions', [ReactionController::class, 'store'])
            ->middleware('throttle:messages')
            ->name('reactions.store');
        Route::delete('messages/{message}/reactions', [ReactionController::class, 'destroy'])
            ->middleware('throttle:messages')
            ->name('reactions.destroy');

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

/*
|--------------------------------------------------------------------------
| File delivery
|--------------------------------------------------------------------------
|
| Deliberately outside the workspace prefix. These URLs are minted by queued
| broadcasts, which have no request and therefore no resolved workspace, and a
| slug in the path would break every existing link the moment a workspace was
| renamed.
|
| `signed` bounds how long a copied link keeps working. It is not what grants
| access: the controller re-resolves the attachment, finds the message it hangs
| off, and puts that message through the container policy on every single hit —
| so a forwarded link is a 404 for someone who was never in the channel, however
| valid its signature.
|
*/

Route::middleware(['auth', 'signed'])->group(function () {
    Route::get('files/{attachment}', [DownloadController::class, 'show'])->name('files.show');
    Route::get('files/{attachment}/thumbnail', [DownloadController::class, 'thumbnail'])
        ->name('files.thumbnail');
});

require __DIR__.'/settings.php';
