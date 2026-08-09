<?php

namespace App\Services;

use App\Enums\ConversationType;
use App\Http\Resources\ChannelGroupResource;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\ProjectGroupResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Builds the navigation payload shared with every workspace page: the
 * workspace rail and the sidebar's channel list.
 *
 * Centralized here because it runs on *every* request inside a workspace, so
 * its query count is a fixed cost the whole app pays. Each method below is one
 * query with its relations eager-loaded; nothing lazy-loads per row.
 */
class WorkspaceNavigator
{
    public function __construct(private readonly UnreadCounter $unread) {}

    /**
     * Every workspace the user belongs to, for the left-hand rail.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rail(User $user): array
    {
        return $user->workspaces()
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'initials' => $workspace->initials(),
                'logo_url' => $workspace->logoUrl(),
            ])
            ->all();
    }

    /**
     * The channels shown in the sidebar: the ones the user has actually joined,
     * ordered by name.
     *
     * The membership row is eager-loaded and constrained to this user, so
     * ChannelResource can read the unread pointer and mute flag without another
     * query per channel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sidebarChannels(Workspace $workspace, User $user): array
    {
        $channels = $workspace->channels()
            ->notArchived()
            ->whereMember($user)
            ->with(['members' => fn ($query) => $query->where('user_id', $user->id)])
            ->orderBy('name')
            ->get();

        return ChannelResource::collection($channels)->resolve();
    }

    /**
     * The headings that channel list is arranged under: this person's sections
     * in this workspace, in the order they drew them.
     *
     * Every one of them, including the empty ones — the opposite of
     * {@see sidebarProjectGroups()}, and for a reason that is really the same
     * reason. Project groups are curated on the project page, so an empty one is
     * kept visible *there* and suppressed here. A channel section has no such
     * page: the sidebar is where it is made, named, and filled, so a section
     * whose last channel just left has to stay on screen or the reader has
     * watched their own heading vanish with no way to get it back.
     *
     * One query, on every workspace page, like everything else in this class.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sidebarChannelGroups(Workspace $workspace, User $user): array
    {
        $groups = ChannelGroup::query()
            ->ownedBy($workspace, $user)
            ->ordered()
            ->get();

        return ChannelGroupResource::collection($groups)->resolve(request());
    }

    /**
     * Public and announcement channels the user has not joined yet, for the
     * "Browse channels" affordance. Guests get nothing — they have no directory.
     *
     * @return array<int, array<string, mixed>>
     */
    public function joinableChannels(Workspace $workspace, User $user): array
    {
        if ($user->roleIn($workspace)?->isGuest() !== false) {
            return [];
        }

        $channels = $workspace->channels()
            ->browsable()
            ->whereDoesntHave('members', fn ($query) => $query->where('user_id', $user->id))
            ->withCount('members')
            ->orderBy('name')
            ->get();

        return ChannelResource::collection($channels)->resolve();
    }

    /**
     * The projects shown in the sidebar: the ones the user is actually on.
     *
     * Membership rather than the whole workspace, even though anyone who is not
     * a guest may *open* any board — see {@see App\Policies\ProjectPolicy}. The
     * sidebar is a list of places you go, and a company's every board in it
     * would bury the two you work on. The rest are one click away in the project
     * list, which is what that page is for.
     *
     * Takes the collection rather than loading it, because the payload sends
     * the groups alongside and both are read off the same rows — see
     * {@see sidebarProjectGroups()}.
     *
     * @param  Collection<int, Project>  $projects  from {@see memberProjects()}
     * @return array<int, array<string, mixed>>
     */
    public function sidebarProjects(Collection $projects): array
    {
        return ProjectResource::collection($projects)->resolve(request());
    }

    /**
     * The headings the sidebar's project list is arranged under.
     *
     * Only the groups that have one of *this* person's projects in them, which
     * is what makes this different from the project page's list. That page is
     * where groups are curated, so an empty one has to stay visible there for
     * something to be dropped into; here an empty heading would be a label over
     * nothing, in the one part of the shell with the least room to spare.
     *
     * Read off the projects already loaded rather than queried again — the
     * relation is eager-loaded, so the groups cost one query no matter how many
     * projects the person is on.
     *
     * @param  Collection<int, Project>  $projects  the same collection sent as `projects`
     * @return array<int, array<string, mixed>>
     */
    public function sidebarProjectGroups(Collection $projects): array
    {
        $groups = $projects
            ->map(fn (Project $project): ?ProjectGroup => $project->group)
            ->filter()
            ->unique('id')
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values();

        return ProjectGroupResource::collection($groups)->resolve(request());
    }

    /**
     * The projects this person is on, with everything the sidebar reads.
     *
     * @return Collection<int, Project>
     */
    public function memberProjects(Workspace $workspace, User $user): Collection
    {
        return $workspace->projects()
            ->notArchived()
            ->whereMember($user)
            ->with([
                'members' => fn ($query) => $query->where('user_id', $user->id),

                // Eager-loaded because the sidebar draws a heading per group:
                // reading it per project would be an N+1 across the whole list,
                // on every page in the workspace.
                'group',
            ])
            ->orderBy('name')
            ->get()
            /*
             * Hand every project the workspace instance we already have.
             *
             * ProjectResource asks the policy what this viewer may do with each
             * project, and ProjectPolicy answers by reading the project's
             * workspace — which, left alone, is a lazy load per project on every
             * page in the workspace. The same fix, for the same reason, as
             * {@see MessageHistory::shareContainer()}.
             */
            ->each(fn (Project $project) => $project->setRelation('workspace', $workspace));
    }

    /**
     * The user's conversations, most recently active first.
     *
     * Both relations are eager-loaded and each serves one purpose:
     * `activeUsers` is the roster the row renders, and `participants` is
     * constrained to this user so ConversationResource can read their own mute
     * flag and read pointer. Loading either lazily would be an N+1 across the
     * whole DM list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sidebarConversations(Workspace $workspace, User $user): array
    {
        $conversations = $workspace->conversations()
            ->visibleTo($user)
            ->with([
                'activeUsers',
                'participants' => fn ($query) => $query->where('user_id', $user->id),
            ])
            /*
             * NULLS LAST is the point of the raw ordering: a conversation that
             * was just started has no `last_message_at`, and Postgres sorts
             * NULLs first on DESC — which would pin every empty thread above
             * every live one.
             */
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('id')
            ->limit((int) config('snack.conversations.sidebar_limit', 30))
            ->get();

        return ConversationResource::collection($conversations)->resolve(request());
    }

    /**
     * The people the DM list is built from: everyone else in the workspace,
     * each carrying the direct conversation the viewer already has with them,
     * or null when there is none yet.
     *
     * # Why the roster and not the threads
     *
     * A colleague you have not messaged is still someone you can message.
     * Listing conversations meant the only way to see a person in the sidebar
     * was to *start* a thread with them, so the list you actually wanted — the
     * team — could only be had by creating a conversation row per member.
     * Nothing here creates anything: a person without a thread is a name, and
     * the conversation is opened on the click that needs it.
     *
     * Guests get an empty roster, matching {@see DirectoryController} — they may
     * not start conversations, so offering them the member directory here would
     * be a back door to the same list the picker refuses them.
     *
     * Both queries are fixed cost: one for the roster, one for the viewer's
     * direct conversations, joined in memory.
     *
     * # Why the order is the name and nothing else
     *
     * This used to lift recently-active threads to the top, the way a list of
     * conversations is ordered. A roster is not that list: every message sent or
     * received yanked a person out of the alphabet and dropped them at the top
     * while the reader was looking at the sidebar, so rows moved under the
     * cursor and the same name was in a different place every time. What
     * recency was there to say — "this one wants you" — is already said by the
     * unread badge and the bold row, in place, without moving anything.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sidebarPeople(Workspace $workspace, User $user): array
    {
        if ($user->roleIn($workspace)?->canStartConversations() !== true) {
            return [];
        }

        $direct = $this->directConversationsByCounterpart($workspace, $user);

        return $workspace->activeUsers()
            ->whereKeyNot($user->id)
            ->orderBy('users.name')
            ->limit((int) config('snack.conversations.people_limit', 100))
            ->get()
            ->map(function (User $person) use ($direct): array {
                $conversation = $direct->get($person->id);

                return [
                    'user' => (new UserResource($person))->resolve(request()),
                    'conversation' => $conversation === null
                        ? null
                        : (new ConversationResource($conversation))->resolve(request()),
                ];
            })
            ->all();
    }

    /**
     * The viewer's one-to-one conversations in this workspace, keyed by the
     * other person's id.
     *
     * Loaded whole rather than reusing {@see sidebarConversations()}, which is
     * capped: a person must still show their unread badge when the thread with
     * them falls outside the most recent thirty.
     *
     * @return Collection<int, Conversation>
     */
    private function directConversationsByCounterpart(Workspace $workspace, User $user): Collection
    {
        return $workspace->conversations()
            ->where('type', ConversationType::Direct)
            ->visibleTo($user)
            ->with([
                'activeUsers',
                'participants' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->get()
            // A direct conversation whose other side is no longer an active
            // participant has no person to hang under, and keying it anyway
            // would file every such row under the same empty key.
            ->filter(fn (Conversation $conversation): bool => $conversation->counterparts($user)->isNotEmpty())
            ->keyBy(fn (Conversation $conversation): int => $conversation->counterparts($user)->first()->id);
    }

    /**
     * The full navigation payload for a workspace page.
     *
     * @return array<string, mixed>
     */
    public function payload(Workspace $workspace, User $user): array
    {
        $channelUnread = $this->unread->forChannels($workspace, $user);
        $conversationUnread = $this->unread->forConversations($workspace, $user);

        // Loaded once and read twice: the list, and the headings it is drawn
        // under. Asking for the groups separately would be a second query for
        // rows this one already has.
        $projects = $this->memberProjects($workspace, $user);

        return [
            'workspace' => (new WorkspaceResource($workspace))->resolve(request()),
            'channels' => $this->sidebarChannels($workspace, $user),

            // The sections those channels are drawn under — this viewer's own,
            // empty ones included. See sidebarChannelGroups().
            'channel_groups' => $this->sidebarChannelGroups($workspace, $user),

            'conversations' => $this->sidebarConversations($workspace, $user),

            // The DM list itself. `conversations` above stays the complete set
            // — huddles, container labels, and unread lookups all read it — and
            // the sidebar renders the groups from it alongside these people.
            'people' => $this->sidebarPeople($workspace, $user),

            // The projects sidebar. Shared with every workspace page for the
            // reason the channel list is: the sidebar is part of the shell, and
            // a list that only loaded on project pages would appear to pop into
            // existence when you walked into that section.
            'projects' => $this->sidebarProjects($projects),

            // The headings those projects are drawn under — only the ones with
            // something of this person's in them. See sidebarProjectGroups().
            'project_groups' => $this->sidebarProjectGroups($projects),

            'unread' => [
                // Cast so an empty PHP array serializes as `{}` rather than
                // `[]`, keeping the client's Record<number, …> type honest.
                'channels' => (object) $channelUnread,
                'conversations' => (object) $conversationUnread,

                /*
                 * Real @-mentions only.
                 *
                 * This badge labels the "Mentions" link, and that page lists
                 * `mentions` rows and nothing else — so anything counted here
                 * that is not a mention row sends the reader to an empty list.
                 * Unread DMs used to be added in, on the reasoning that every
                 * message in a conversation is addressed to you; true, but it
                 * made the badge disagree with the page it points at, which is
                 * the one thing a badge may never do. DMs are already badged
                 * loudly on their own sidebar rows, where clicking through
                 * actually shows them.
                 */
                'mentions' => $this->unread->mentionCount($workspace, $user),

                // How many followed threads have unread replies — see
                // UnreadCounter::threadCount() for why it counts threads and
                // not replies.
                'threads' => $this->unread->threadCount($workspace, $user),

                // The notification centre's own badge.
                'notifications' => $this->unread->notificationCount($workspace, $user),
            ],
        ];
    }

    /**
     * Where to send someone who lands on a workspace without naming a channel:
     * their first joined channel, preferring the workspace's default.
     */
    public function landingChannel(Workspace $workspace, User $user): ?Channel
    {
        return $workspace->channels()
            ->notArchived()
            ->whereMember($user)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }
}
