<?php

use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\Channels\ChannelArchiveController;
use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\ChannelMemberController;
use App\Http\Controllers\Channels\ChannelMembershipController;
use App\Http\Controllers\Channels\ChannelPreferenceController;
use App\Http\Controllers\Channels\ChannelReadController;
use App\Http\Controllers\Channels\HuddleController as ChannelHuddleController;
use App\Http\Controllers\Channels\MentionableController;
use App\Http\Controllers\Channels\MessageController as ChannelMessageController;
use App\Http\Controllers\Conversations\ConversationController;
use App\Http\Controllers\Conversations\ConversationPreferenceController;
use App\Http\Controllers\Conversations\ConversationReadController;
use App\Http\Controllers\Conversations\DirectoryController;
use App\Http\Controllers\Conversations\HuddleController as ConversationHuddleController;
use App\Http\Controllers\Conversations\MessageController as ConversationMessageController;
use App\Http\Controllers\Conversations\ParticipantController;
use App\Http\Controllers\Files\DownloadController;
use App\Http\Controllers\Files\FileBrowserController;
use App\Http\Controllers\Huddles\HuddleHistoryController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\Messages\MentionController;
use App\Http\Controllers\Messages\PinController;
use App\Http\Controllers\Messages\ReactionController;
use App\Http\Controllers\Messages\SaveController;
use App\Http\Controllers\Messages\ThreadController;
use App\Http\Controllers\Messages\ThreadReadController;
use App\Http\Controllers\Messages\ThreadSubscriptionController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Notifications\NotificationReadController;
use App\Http\Controllers\Presence\PresenceController;
use App\Http\Controllers\Projects\BacklogController;
use App\Http\Controllers\Projects\BoardColumnController;
use App\Http\Controllers\Projects\BoardController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectGroupController;
use App\Http\Controllers\Projects\ProjectMemberController;
use App\Http\Controllers\Projects\ProjectMembershipController;
use App\Http\Controllers\Projects\SprintController;
use App\Http\Controllers\Projects\TaskController;
use App\Http\Controllers\Projects\TaskLabelController;
use App\Http\Controllers\Projects\TaskMoveController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\Standups\StandupController;
use App\Http\Controllers\Standups\StandupResponseController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\Workspaces\AuditLogController;
use App\Http\Controllers\Workspaces\IncomingWebhookController;
use App\Http\Controllers\Workspaces\InvitationController;
use App\Http\Controllers\Workspaces\InviteLinkController;
use App\Http\Controllers\Workspaces\LogoController;
use App\Http\Controllers\Workspaces\MemberController;
use App\Http\Controllers\Workspaces\SettingsController;
use App\Http\Middleware\EnsureInstanceIsSetUp;
use App\Http\Middleware\EnsureInstanceNeedsSetup;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| First-run setup
|--------------------------------------------------------------------------
|
| Only reachable while the installation has no accounts. Snack is
| invitation-first, so without this a fresh install has no way in at all:
| registration 404s because nobody holds an invitation, and the login form has
| nothing to authenticate against.
|
*/

Route::middleware(EnsureInstanceNeedsSetup::class)->group(function () {
    Route::get('setup', [SetupController::class, 'create'])->name('setup.create');

    Route::post('setup', [SetupController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('setup.store');
});

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::inertia('/', 'welcome')
    ->middleware(EnsureInstanceIsSetUp::class)
    ->name('home');

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

    /*
     * Presence.
     *
     * Outside the workspace prefix because presence describes the person, not
     * the room they are currently in — someone signed into two workspaces has
     * one availability, and scoping this would give them two that overwrite
     * each other.
     */
    Route::post('presence', [PresenceController::class, 'store'])
        ->middleware('throttle:presence')
        ->name('presence.store');

    Route::delete('presence', [PresenceController::class, 'destroy'])
        ->middleware('throttle:presence')
        ->name('presence.destroy');
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

        /*
         * A channel's shared settings, gated on ChannelPolicy::update — which
         * admits workspace admins and the channel's own admins.
         *
         * Archiving is its own pair of idempotent endpoints rather than a field
         * on the form above: it is not an edit, it closes the channel, and a
         * toggle would let a double-submitted "Archive" quietly restore it.
         */
        Route::patch('channels/{channel}', [ChannelController::class, 'update'])
            ->name('channels.update');

        Route::post('channels/{channel}/archive', [ChannelArchiveController::class, 'store'])
            ->name('channels.archive.store');
        Route::delete('channels/{channel}/archive', [ChannelArchiveController::class, 'destroy'])
            ->name('channels.archive.destroy');

        Route::post('channels/{channel}/membership', [ChannelMembershipController::class, 'store'])
            ->name('channels.membership.store');
        Route::delete('channels/{channel}/membership', [ChannelMembershipController::class, 'destroy'])
            ->name('channels.membership.destroy');

        /*
         * Adding *other* people, which is a different endpoint from the pair
         * above rather than a parameter on it: joining yourself is gated on the
         * channel being open to you, adding someone else on administering the
         * channel. Sharing a route would make which policy applies depend on
         * what is in the body.
         */
        Route::post('channels/{channel}/members', [ChannelMemberController::class, 'store'])
            ->name('channels.members.store');

        // Muting a channel and setting its notification level. The mirror of
        // conversations.preferences.update, and private to the member who asks:
        // silencing a busy channel must not silence it for anyone else.
        Route::patch('channels/{channel}/preferences', [ChannelPreferenceController::class, 'update'])
            ->name('channels.preferences.update');

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

        /*
         * The channel's huddle.
         *
         * A singleton on the container rather than a resource with an id, for
         * the reason the invite link is: there is only ever one live huddle in a
         * channel, and naming it in the URL would invite a request that hangs up
         * on a different channel's call.
         *
         * Starting and joining are the same POST — see Channels\HuddleController
         * — and both are throttled as sends rather than as reads: each one can
         * mint a credential and open a media connection, which is far more
         * expensive than a row.
         */
        Route::post('channels/{channel}/huddle', [ChannelHuddleController::class, 'store'])
            ->middleware('throttle:huddles')
            ->name('channels.huddle.store');

        // Leaving is deliberately *not* throttled: it is called on unload and on
        // tab close as well as by the button, and a rate-limited hang-up leaves
        // somebody showing as present in a call they walked out of.
        Route::delete('channels/{channel}/huddle', [ChannelHuddleController::class, 'destroy'])
            ->name('channels.huddle.destroy');

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

        // The DM home the navigation rail points at. Declared before the
        // {conversation} routes below so the bare word is never read as an id.
        Route::get('dms', [ConversationController::class, 'index'])
            ->name('conversations.index');

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

        // The conversation's huddle — the mirror of the channel pair above, and
        // what "call me" means in a DM.
        Route::post('dms/{conversation}/huddle', [ConversationHuddleController::class, 'store'])
            ->middleware('throttle:huddles')
            ->name('conversations.huddle.store');

        Route::delete('dms/{conversation}/huddle', [ConversationHuddleController::class, 'destroy'])
            ->name('conversations.huddle.destroy');

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
         * Activity: notifications, mentions, and followed threads as tabs over
         * the one question they all answer.
         *
         * The three routes above keep their own addresses — they are still
         * linked, still bookmarked, and the sidebar still points at Threads —
         * and every one of them reads the same App\Services\ActivityFeed as the
         * tab that mirrors it.
         */
        Route::get('activity', [ActivityController::class, 'index'])
            ->name('activity.index');

        /*
         * Huddles that have happened. A huddle leaves no message behind, so
         * without this record a call missed by ten minutes is indistinguishable
         * from one that never happened.
         */
        Route::get('huddles', [HuddleHistoryController::class, 'index'])
            ->name('huddles.index');

        /*
         * The notification centre.
         *
         * Its own inbox rather than a filter over Mentions: a mention is a thing
         * said in a room you are in, while a notification is a record of Snack
         * raising something to you — which is also how a DM and a followed
         * thread reach you, and neither of those is a mention.
         *
         * Marking one read is a POST that answers with JSON; the client stamps a
         * row as the reader clicks through to it, and re-rendering the page they
         * are leaving would be work nobody sees. Marking everything read is a
         * PATCH on the collection, and redirects, because it changes the page
         * you are looking at.
         */
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');

        Route::patch('notifications/read', [NotificationReadController::class, 'update'])
            ->name('notifications.read.update');

        Route::post('notifications/{notification}/read', [NotificationReadController::class, 'store'])
            ->middleware('throttle:message-reads')
            ->name('notifications.read.store');

        // The member's own notification defaults for this workspace, plus the
        // per-channel and per-conversation overrides they have made.
        Route::get('notification-preferences', [NotificationPreferenceController::class, 'edit'])
            ->name('notification-preferences.edit');
        Route::patch('notification-preferences', [NotificationPreferenceController::class, 'update'])
            ->name('notification-preferences.update');

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

        /*
         *--------------------------------------------------------------------
         * Projects
         *--------------------------------------------------------------------
         *
         * A project is addressed by slug like a channel, and scopeBindings()
         * ties {project} to this workspace's own — slugs are only unique per
         * workspace, so without it a member of one workspace could address
         * another's board.
         *
         * Nested {task}, {sprint}, {column} and {label} are likewise scoped to
         * the project in the path, which is what stops a request naming this
         * team's project and another team's card.
         *
         * Two response shapes live side by side here, deliberately:
         *
         *  - The pages (board, backlog, settings) and everything that changes
         *    the *shape* of a board — columns, labels, sprints, membership —
         *    are ordinary Inertia visits, because each one changes the page as
         *    a whole.
         *  - The card endpoints answer with JSON. The board is a live list the
         *    client already holds, and re-rendering it to add one card would
         *    throw away the reader's scroll position and any panel they had
         *    open. See Projects\TaskController.
         */
        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

        /*
         * The headings the project list is arranged under.
         *
         * Their own path rather than `projects/groups`, which {project} would
         * otherwise swallow — "groups" is a legal project slug. Addressed by id
         * because a group has no page of its own for a slug to carry.
         *
         * Which project sits under which heading is set on the project itself,
         * through projects.update: arranging the shelves and moving somebody
         * else's project between them are different rights.
         */
        Route::post('project-groups', [ProjectGroupController::class, 'store'])
            ->name('project-groups.store');
        Route::patch('project-groups/{projectGroup}', [ProjectGroupController::class, 'update'])
            ->name('project-groups.update');
        Route::post('project-groups/{projectGroup}/move', [ProjectGroupController::class, 'move'])
            ->name('project-groups.move');
        Route::delete('project-groups/{projectGroup}', [ProjectGroupController::class, 'destroy'])
            ->name('project-groups.destroy');

        Route::get('projects/{project}', [BoardController::class, 'show'])->name('projects.board');
        Route::get('projects/{project}/backlog', [BacklogController::class, 'index'])->name('projects.backlog');

        Route::get('projects/{project}/settings', [ProjectController::class, 'edit'])
            ->name('projects.settings.edit');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])
            ->name('projects.update');

        // Which heading the project sits under. Separate from the update above
        // because it is done from the list, which is not where that one leaves
        // you — see Projects\ProjectController::assignGroup().
        Route::patch('projects/{project}/group', [ProjectController::class, 'assignGroup'])
            ->name('projects.group.update');

        Route::post('projects/{project}/archive', [ProjectController::class, 'archive'])
            ->name('projects.archive.store');
        Route::delete('projects/{project}/archive', [ProjectController::class, 'unarchive'])
            ->name('projects.archive.destroy');

        // Joining and leaving under your own steam, which is a different right
        // from adding somebody else — see Projects\ProjectMembershipController.
        Route::post('projects/{project}/membership', [ProjectMembershipController::class, 'store'])
            ->name('projects.membership.store');
        Route::delete('projects/{project}/membership', [ProjectMembershipController::class, 'destroy'])
            ->name('projects.membership.destroy');

        Route::post('projects/{project}/members', [ProjectMemberController::class, 'store'])
            ->name('projects.members.store');
        Route::patch('projects/{project}/members/{member}', [ProjectMemberController::class, 'update'])
            ->name('projects.members.update');
        Route::delete('projects/{project}/members/{member}', [ProjectMemberController::class, 'destroy'])
            ->name('projects.members.destroy');

        // The board's own shape.
        Route::post('projects/{project}/columns', [BoardColumnController::class, 'store'])
            ->name('projects.columns.store');
        Route::patch('projects/{project}/columns/{column}', [BoardColumnController::class, 'update'])
            ->name('projects.columns.update');
        Route::delete('projects/{project}/columns/{column}', [BoardColumnController::class, 'destroy'])
            ->name('projects.columns.destroy');

        Route::post('projects/{project}/labels', [TaskLabelController::class, 'store'])
            ->name('projects.labels.store');
        Route::patch('projects/{project}/labels/{label}', [TaskLabelController::class, 'update'])
            ->name('projects.labels.update');
        Route::delete('projects/{project}/labels/{label}', [TaskLabelController::class, 'destroy'])
            ->name('projects.labels.destroy');

        /*
         * Sprints. Starting and completing are their own endpoints rather than
         * fields on the update, for the reason archiving a channel is: neither
         * is an edit — one replaces the board and the other empties it — and a
         * double-submitted "Complete" must not reopen the sprint it just closed.
         */
        Route::post('projects/{project}/sprints', [SprintController::class, 'store'])
            ->name('projects.sprints.store');
        Route::patch('projects/{project}/sprints/{sprint}', [SprintController::class, 'update'])
            ->name('projects.sprints.update');
        Route::delete('projects/{project}/sprints/{sprint}', [SprintController::class, 'destroy'])
            ->name('projects.sprints.destroy');

        Route::post('projects/{project}/sprints/{sprint}/start', [SprintController::class, 'start'])
            ->name('projects.sprints.start');
        Route::post('projects/{project}/sprints/{sprint}/complete', [SprintController::class, 'complete'])
            ->name('projects.sprints.complete');

        // Cards.
        Route::post('projects/{project}/tasks', [TaskController::class, 'store'])
            ->middleware('throttle:messages')
            ->name('projects.tasks.store');

        /*
         * Edits, moves and deletes address the task directly: a task id already
         * names its project, and a route carrying both invites a request that
         * names one project and a card from another.
         *
         * The move is throttled as traffic rather than as a form — it fires on
         * every drop, several times a minute — while the edit is not: it is a
         * person typing into a panel.
         */
        Route::get('tasks/{task}', [TaskController::class, 'show'])
            ->middleware('throttle:message-reads')
            ->name('tasks.show');
        Route::patch('tasks/{task}', [TaskController::class, 'update'])
            ->name('tasks.update');
        Route::delete('tasks/{task}', [TaskController::class, 'destroy'])
            ->name('tasks.destroy');

        Route::patch('tasks/{task}/position', [TaskMoveController::class, 'update'])
            ->middleware('throttle:messages')
            ->name('tasks.move');

        /*
         * Workspace administration.
         *
         * The logo is on its own endpoints for the reason the avatar is: a file
         * needs a multipart request, and folding it into the settings form would
         * re-validate the member limit every time somebody changed a picture.
         *
         * The audit log is read-only and has no write route at all — entries are
         * written by the actions that cause them.
         */
        Route::get('settings', [SettingsController::class, 'edit'])->name('workspace-settings.edit');
        Route::patch('settings', [SettingsController::class, 'update'])->name('workspace-settings.update');

        Route::post('settings/logo', [LogoController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('workspace-logo.store');
        Route::delete('settings/logo', [LogoController::class, 'destroy'])
            ->name('workspace-logo.destroy');

        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

        /*
         *--------------------------------------------------------------------
         * Incoming webhooks
         *--------------------------------------------------------------------
         *
         * Managing the URLs that let outside systems post into a channel. The
         * deliveries themselves are not here at all — they arrive on
         * routes/webhooks.php, outside this prefix and outside `web`, because a
         * deploy script has no session and a workspace slug in the path would
         * break every integration the day somebody renames the workspace.
         *
         * Admin-gated including the index, which is unusual: the list shows live
         * tokens, so reading it is the same right as issuing one. See
         * App\Policies\IncomingWebhookPolicy.
         *
         * Addressed by id, like {standup} and for the same reasons: a hook has
         * no page of its own for a slug to carry, and scopeBindings() ties
         * {webhook} to this workspace's own — so an id from another tenant 404s
         * at the router rather than at a policy that would have to catch it.
         */
        Route::get('webhooks', [IncomingWebhookController::class, 'index'])
            ->name('webhooks.index');
        Route::post('webhooks', [IncomingWebhookController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('webhooks.store');
        Route::patch('webhooks/{webhook}', [IncomingWebhookController::class, 'update'])
            ->name('webhooks.update');

        /*
         * Turning one off. A PATCH rather than a DELETE because the row
         * survives — the point of revoking is that the incident stays readable
         * afterwards. See App\Actions\Webhooks\RevokeIncomingWebhook.
         */
        Route::patch('webhooks/{webhook}/revoke', [IncomingWebhookController::class, 'revoke'])
            ->name('webhooks.revoke');

        /*
         * And the undo. Its own endpoint rather than `update` with a flag,
         * matching revoke: this puts a live credential back into the world, so
         * it gets its own policy check and its own audit entry rather than
         * hiding inside a rename.
         */
        Route::patch('webhooks/{webhook}/restore', [IncomingWebhookController::class, 'restore'])
            ->name('webhooks.restore');

        /*
         * The optional signing secret: require it, stop requiring it, or replace
         * it. Separate from `update` because one of the three takes a security
         * control away, and it has its own policy method so that permission
         * cannot be widened by loosening the one that governs renaming.
         */
        Route::patch('webhooks/{webhook}/signing', [IncomingWebhookController::class, 'updateSigning'])
            ->name('webhooks.signing');

        Route::delete('webhooks/{webhook}', [IncomingWebhookController::class, 'destroy'])
            ->name('webhooks.destroy');

        /*
         *--------------------------------------------------------------------
         * Standups
         *--------------------------------------------------------------------
         *
         * The schedules themselves are workspace administration — they arrange
         * recurring direct messages to everybody in a channel, which is the one
         * thing an ordinary member could otherwise set up that lands in other
         * people's inboxes without their asking. See App\Policies\StandupPolicy.
         *
         * Addressed by id rather than by a slug: a standup has no page of its
         * own for a slug to carry, and scopeBindings() ties {standup} to this
         * workspace's own — so an id from another tenant 404s at the router
         * rather than at a policy that would have to catch it.
         */
        Route::get('standups', [StandupController::class, 'index'])
            ->name('standups.index');
        Route::post('standups', [StandupController::class, 'store'])
            ->name('standups.store');
        Route::patch('standups/{standup}', [StandupController::class, 'update'])
            ->name('standups.update');
        Route::delete('standups/{standup}', [StandupController::class, 'destroy'])
            ->name('standups.destroy');

        /*
         * Answering one, which is a different right held by different people:
         * not an admin's, but the person who was asked. Deliberately outside the
         * path above — a response row already names its standup, and a route
         * carrying both invites a request that names one standup and somebody
         * else's answer to another.
         *
         * Throttled as a send rather than a read: it posts to a channel the
         * moment the last person answers.
         *
         * `withoutScopedBindings()` because there is no relation for the scoping
         * to travel: a response hangs off a run, which hangs off a standup,
         * which hangs off the workspace — three hops, which `hasManyThrough`
         * does not reach across, so unlike {message} and {task} this cannot be
         * resolved through a tenant relation. Nothing is lost by it. Tenancy is
         * not what protects this row: ownership is, and
         * StandupPolicy::respond() refuses anybody but the person who was asked
         * — with denyAsNotFound, so a probed id does not even confirm it exists.
         */
        Route::post('standup-responses/{response}', [StandupResponseController::class, 'store'])
            ->middleware('throttle:messages')
            ->withoutScopedBindings()
            ->name('standup-responses.store');

        // Members
        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

        // Invitations
        Route::get('invitations', [InvitationController::class, 'index'])->name('workspace-invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('workspace-invitations.store');
        /*
         * The workspace's one shareable invite link. Addressed as a singleton
         * rather than by id: there is only ever one live link, and naming it in
         * the URL would invite a request that revokes another workspace's.
         */
        Route::post('invite-link', [InviteLinkController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('workspace-invite-link.store');

        Route::delete('invite-link', [InviteLinkController::class, 'destroy'])
            ->name('workspace-invite-link.destroy');

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
