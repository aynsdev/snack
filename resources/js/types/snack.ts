/**
 * Snack domain types.
 *
 * Each type mirrors exactly one PHP API Resource. When you change a resource,
 * change its counterpart here — the pairing is noted above every type, and it
 * is the only thing keeping the Inertia payload honest.
 */

// -- Enums -------------------------------------------------------------------
// String unions rather than TS enums: these arrive as JSON strings, and a union
// narrows on comparison without any runtime representation.

/** Mirrors App\Enums\WorkspaceRole. */
export type WorkspaceRole = 'owner' | 'admin' | 'member' | 'guest';

/** Mirrors App\Enums\MemberStatus. */
export type MemberStatus = 'active' | 'suspended';

/** Mirrors App\Enums\ChannelType. */
export type ChannelType = 'public' | 'private' | 'announcement';

/** Mirrors App\Enums\ChannelRole. */
export type ChannelRole = 'admin' | 'member';

/** Mirrors App\Enums\ConversationType. */
export type ConversationType = 'direct' | 'group';

/** Mirrors App\Enums\MessageType. */
export type MessageType = 'text' | 'file' | 'system' | 'announcement';

/** Mirrors App\Enums\MentionType. */
export type MentionType = 'user' | 'channel' | 'everyone' | 'here';

/** Mirrors App\Enums\NotificationLevel. */
export type NotificationLevel = 'all' | 'mentions' | 'nothing';

/**
 * Mirrors App\Enums\NotificationReason, which is also the `type` column of a
 * stored notification.
 *
 * There is deliberately no case for an ordinary channel message: that level
 * alerts the reader and can email them, but writes no inbox row. See the PHP
 * enum for why one row per member per message is the shape Snack does not store.
 */
export type NotificationReason = 'mention' | 'direct_message' | 'thread_reply';

/**
 * Mirrors App\Enums\ActivityTab: which slice of the Activity page is open.
 *
 * `all` is the three lists interleaved by time rather than a fourth list of its
 * own, which is why the page can render every tab from the same rows.
 */
export type ActivityTab = 'all' | 'notifications' | 'mentions' | 'threads';

/** Mirrors App\Enums\PresenceStatus. */
export type PresenceStatus = 'online' | 'away' | 'offline' | 'do_not_disturb';

/** Lifecycle of a workspace invitation, from WorkspaceInvitation::status(). */
export type InvitationStatus =
    | 'pending'
    | 'accepted'
    | 'revoked'
    | 'expired'
    // A shareable link that has been redeemed its full `max_uses` times. Only
    // links can reach this: an emailed invitation is spent via `accepted_at`.
    | 'used_up';

// -- Core records ------------------------------------------------------------

/** Mirrors App\Http\Resources\UserResource. */
export type SnackUser = {
    id: number;
    name: string;
    username: string;
    initials: string;
    avatar_url: string | null;
    title: string | null;
    department: string | null;
    presence_status: PresenceStatus;
    status_emoji: string | null;
    status_message: string | null;
};

/**
 * What the current user may do in the current workspace.
 *
 * Computed server-side from the role hierarchy so the client never
 * reimplements it. These flags only decide what to *render*; the server
 * re-authorizes every request regardless.
 */
export type WorkspaceAbilities = {
    administer: boolean;
    manage_members: boolean;
    create_channels: boolean;
    start_conversations: boolean;
    mention_everyone: boolean;
    view_audit_logs: boolean;
    is_guest: boolean;
};

/** Mirrors App\Http\Resources\WorkspaceResource. */
export type Workspace = {
    id: number;
    name: string;
    slug: string;
    initials: string;
    logo_url: string | null;
    description: string | null;
    member_limit: number;
    created_at: string | null;
    role: WorkspaceRole | null;
    abilities: WorkspaceAbilities;
};

/** The trimmed shape used by the workspace rail. */
export type WorkspaceSummary = {
    id: number;
    name: string;
    slug: string;
    initials: string;
    logo_url: string | null;
};

/** Mirrors App\Http\Resources\ChannelResource. */
export type Channel = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    topic: string | null;
    type: ChannelType;
    is_private: boolean;
    is_archived: boolean;
    is_default: boolean;
    last_message_at: string | null;
    /** Only present when the query counted members. */
    member_count?: number;
    is_member: boolean;
    is_muted: boolean;
    notification_level: NotificationLevel | null;
    last_read_message_id: number | null;
    created_at: string | null;
};

/**
 * Mirrors App\Http\Resources\MessageResource.
 *
 * `body` is the author's source text, never HTML. It is rendered by tokenizing
 * it into React elements — see lib/message-format.ts — so it is never handed to
 * `dangerouslySetInnerHTML` and never parsed as markup.
 */
export type Message = {
    id: number;
    workspace_id: number;
    channel_id: number | null;
    /** The conversation's opaque public id — see `Conversation.id`. */
    conversation_id: string | null;
    parent_message_id: number | null;
    body: string;
    type: MessageType;
    /** Null for system messages and for users who have since been deleted. */
    sender: SnackUser | null;
    reply_count: number;
    last_reply_at: string | null;
    /** The newest reply's id — what a thread's read pointer is compared against. */
    last_reply_id: number | null;
    reactions: ReactionGroup[];
    /** Shared with the whole container, unlike `is_saved`. */
    is_pinned: boolean;
    has_attachments: boolean;
    /**
     * The files themselves. Empty when the relation was not loaded, which
     * `has_attachments` distinguishes from "genuinely has none".
     */
    attachments: Attachment[];
    is_edited: boolean;
    edited_at: string | null;
    created_at: string | null;
    /**
     * Absent on messages that arrived over the WebSocket: one broadcast reaches
     * every subscriber, so it cannot carry per-viewer permissions. The timeline
     * fills in a local hint for those; the server re-authorizes regardless.
     */
    can?: MessageAbilities;
    /**
     * Whether *this viewer* has bookmarked it. Absent on messages that arrived
     * over the WebSocket, for the same reason `can` is: a save is private, so
     * one broadcast has no single answer to carry.
     */
    is_saved?: boolean;
};

export type MessageAbilities = {
    update: boolean;
    delete: boolean;
    pin: boolean;
};

/**
 * One emoji's reactions on a message.
 *
 * Carries `user_ids` rather than a `reacted` flag because this exact shape is
 * also the broadcast payload, and one broadcast reaches every subscriber — there
 * is no single viewer to compute "did *you* react" for. The client finds its own
 * id in the list.
 *
 * Both lists are capped server-side (`snack.reactions.named_reactors`) so the
 * payload does not grow with the size of the channel; `count` stays exact.
 */
export type ReactionGroup = {
    /** A shortcode such as "tada", never the rendered glyph. */
    emoji: string;
    count: number;
    user_ids: number[];
    names: string[];
};

/**
 * A message the user has sent that the server has not confirmed yet.
 *
 * Keyed by a client-generated nonce rather than an id, because it does not have
 * one until the server replies.
 */
export type PendingMessage = {
    nonce: string;
    body: string;
    created_at: string;
    sender: SnackUser;
    failed: boolean;
    /**
     * How many files are going out with it.
     *
     * The uploads themselves have already finished by the time a send starts, so
     * this is only ever a label — but a files-only message has an empty body, and
     * without it the optimistic row would render as nothing at all.
     */
    attachmentCount: number;
    /** The ids to re-claim if the send failed and is retried. */
    attachmentIds: number[];
};

/** One page of cursor-paginated history. */
export type MessagePage = {
    messages: Message[];
    has_more: boolean;
    next_cursor: number | null;
};

/**
 * A thread: its root, and a page of replies.
 *
 * Replies page *forward* from the root — a thread is read start to finish —
 * where a timeline pages backward from the newest message. Same cursor on the
 * same monotonic id, opposite direction.
 */
export type ThreadPage = {
    root: Message;
    replies: Message[];
    has_more: boolean;
    next_cursor: number | null;
    subscription: ThreadSubscriptionState;
};

/** Where the current viewer stands in a thread. */
export type ThreadSubscriptionState = {
    is_subscribed: boolean;
    last_read_reply_id: number | null;
};

/**
 * One row in the Threads view.
 *
 * `container` deliberately carries no display name for a conversation: an
 * unnamed DM is titled by whoever else is in it, which differs per viewer, so
 * the client derives it from the sidebar it already holds.
 */
export type ThreadSummary = {
    root: Message;
    container: ThreadContainer | null;
    is_unread: boolean;
    last_read_reply_id: number | null;
};

/**
 * One row in the Mentions view — a history of what was addressed to you.
 *
 * `is_read` reflects the mention row's own stamp, which the channel's read
 * pointer clears. The row survives being read, so this list still answers
 * "what was I asked about last week" long after the badge went away.
 */
export type MentionSummary = {
    id: number;
    type: MentionType;
    is_read: boolean;
    message: Message;
    container: ThreadContainer | null;
};

export type ThreadContainer =
    | {
          kind: 'channel';
          id: number;
          name: string;
          slug: string;
          type: ChannelType;
      }
    | { kind: 'conversation'; id: string; type: ConversationType };

// -- Notifications -----------------------------------------------------------

/**
 * One row of the notification centre.
 *
 * Mirrors App\Http\Resources\NotificationResource — and, deliberately, the
 * broadcast payload too, so an arriving notification can be prepended to the
 * rendered list without a second type to convert between.
 *
 * `preview` and `actor` are a snapshot of the message as it was when the
 * notification was raised, not a live read of it. That is what lets a
 * notification render without the message still existing; the inbox separately
 * drops rows whose message has since been deleted.
 */
export type NotificationEntry = {
    /** A uuid, not a number: this is Laravel's notifications table. */
    id: string;
    type: NotificationReason;
    is_read: boolean;
    read_at: string | null;
    created_at: string | null;
    /**
     * How many messages this one row stands for.
     *
     * Above 1 when App\Services\NotificationCoalescer has folded later direct
     * messages into a row still sitting unread, in which case the fields below
     * describe the *latest* of them.
     */
    count: number;
    message_id: number | null;
    /** Set when the notification is about a thread reply. */
    thread_root_id: number | null;
    preview: string;
    actor: NotificationActor | null;
    container: ThreadContainer | null;
};

/** Just enough of the person who caused a notification to render its row. */
export type NotificationActor = {
    id: number;
    name: string;
    username: string;
    initials: string;
    avatar_url: string | null;
};

/**
 * A member's notification defaults for one workspace. Mirrors
 * WorkspaceMember::notificationSettings().
 */
export type NotificationPreferences = {
    /** The level a newly joined channel starts at. */
    default_level: NotificationLevel;
    email_enabled: boolean;
    digest_enabled: boolean;
    /** Quiet hours in the member's own timezone, or null when turned off. */
    do_not_disturb: { start: string; end: string } | null;
};

// -- Administration ----------------------------------------------------------

/**
 * One entry in the workspace audit log. Mirrors
 * App\Http\Resources\AuditLogResource.
 *
 * `actor` is null when the person who performed the action has since been
 * deleted — the entry deliberately outlives them, which is precisely when an
 * audit trail matters most.
 *
 * The two value bags have no fixed schema: a rename carries a name, a role
 * change carries a role. They are rendered as key/value pairs rather than
 * pretending otherwise.
 */
export type AuditEntry = {
    id: number;
    /** The full `subject.verb` value, e.g. "member.role_changed". */
    action: string;
    /** Resolved server-side from the PHP enum, so this file holds no copy. */
    action_label: string;
    /** The `subject` prefix, which is what the filter works on. */
    subject: string;
    actor: SnackUser | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string | null;
};

/** The workspace's own settings, as the admin screen renders them. */
export type WorkspaceSettings = {
    /** Whether admins may currently invite anybody at all. */
    allow_invitations: boolean;
    /** Off makes every channel effectively invite-only. */
    allow_self_serve_joins: boolean;
};

// -- Files -------------------------------------------------------------------

/** How the client renders a file. Mirrors MessageAttachment::kind(). */
export type AttachmentKind = 'image' | 'video' | 'audio' | 'pdf' | 'file';

/**
 * A file on a message. Mirrors App\Http\Resources\AttachmentResource.
 *
 * There is no path here and never will be. The three URLs are signed routes that
 * re-authorize the viewer against the message's container on every hit, so a
 * copied link is useless to anyone who could not already read the message.
 *
 * `thumbnail_url` is null until the queue has processed the upload — the client
 * shows a placeholder until then — and stays null for anything that is not a
 * decodable image. `preview_url` is null above the inline-preview size ceiling,
 * which is what makes a 40 MB PNG render as a chip rather than as a download
 * that started on its own.
 */
export type Attachment = {
    id: number;
    name: string;
    mime_type: string;
    size: number;
    /** Pre-formatted server-side so every surface says "1.4 MB" the same way. */
    human_size: string;
    kind: AttachmentKind;
    /**
     * Display dimensions, already corrected for EXIF orientation — a portrait
     * phone photo reports portrait here even though its raw buffer is
     * landscape. Used as the aspect ratio that reserves space before the bytes
     * arrive.
     */
    width: number | null;
    height: number | null;
    /**
     * Whether the file moves. False until the queue has processed the upload,
     * so a GIF starts playing a moment after it is sent rather than never.
     */
    is_animated: boolean;
    download_url: string;
    thumbnail_url: string | null;
    preview_url: string | null;
    created_at: string | null;
};

/**
 * A file in the composer's tray, before the message carrying it is sent.
 *
 * Local until the upload completes: `attachment` is null while the bytes are
 * still going up, which is also when `progress` means anything. A failure keeps
 * the row so it can be retried or removed, rather than making the file the
 * author picked silently disappear.
 */
export type PendingUpload = {
    /** Client-side only, so the tray has a stable key before the server answers. */
    nonce: string;
    name: string;
    size: number;
    /** 0–100. */
    progress: number;
    /** Set once the server has taken the file; this is what a send claims. */
    attachment: Attachment | null;
    error: string | null;
    /** An object URL for images, so the tray previews before the upload lands. */
    localPreview: string | null;
};

/** One row in the Files browser. */
export type FileRow = Attachment & {
    uploader: SnackUser | null;
    container: ThreadContainer | null;
    message_id: number | null;
};

// -- Search ------------------------------------------------------------------

/**
 * What the server made of the search box's contents.
 *
 * The filters are echoed back parsed rather than left for the client to re-read,
 * so the chips above the results say exactly what the query actually did — not
 * what the client guessed it did.
 */
export type ParsedSearchQuery = {
    raw: string;
    terms: string;
    from: string[];
    in: string[];
    has_file: boolean;
    before: string | null;
    after: string | null;
    /** Nothing to search for: no usable terms and no filters. */
    is_empty: boolean;
    /** Something was typed, but it did not amount to a query. */
    too_short: boolean;
};

/**
 * One piece of a result snippet.
 *
 * Snippets arrive pre-split rather than as markup: this application renders no
 * HTML from message content anywhere, so `ts_headline`'s marks are turned into
 * these on the server and drawn as React elements here. Nothing is ever handed
 * to `dangerouslySetInnerHTML`.
 */
export type SnippetSegment = {
    text: string;
    highlight: boolean;
};

export type SearchResult = {
    message: Message;
    container: ThreadContainer | null;
    /** Empty when there was nothing to highlight, e.g. a filters-only search. */
    snippet: SnippetSegment[];
};

/** Mirrors App\Http\Resources\WorkspaceMemberResource. */
export type WorkspaceMember = {
    id: number;
    role: WorkspaceRole;
    role_label: string;
    status: MemberStatus;
    joined_at: string | null;
    user: SnackUser;
    /** Only exposed on admin screens. */
    email?: string;
};

/** Mirrors App\Http\Resources\WorkspaceInvitationResource. */
export type WorkspaceInvitation = {
    id: number;
    /** Null for a shareable invite link, which is addressed to nobody. */
    email: string | null;
    is_link: boolean;
    /** Null means unlimited within the expiry window. */
    max_uses: number | null;
    use_count: number;
    /**
     * The URL to hand out. Present only where the server opted in via
     * `WorkspaceInvitationResource::withUrl()` — an emailed invitation never
     * has one, because its plaintext token lives only in the message that
     * carried it.
     */
    url?: string | null;
    role: WorkspaceRole;
    role_label: string;
    status: InvitationStatus;
    expires_at: string;
    accepted_at: string | null;
    revoked_at: string | null;
    created_at: string | null;
    invited_by?: SnackUser;
};

/**
 * Mirrors App\Http\Resources\ConversationResource.
 *
 * Almost every field is viewer-specific. `display_name` in particular is *not* a
 * property of the row: an unnamed conversation is titled by the other people in
 * it, which is a different string for each participant. That is why no broadcast
 * payload ever carries this shape.
 */
export type Conversation = {
    /**
     * An opaque ULID, not the database key. `/dms/1` would advertise that
     * `/dms/2` exists and let anyone measure how many private conversations
     * the deployment holds, so the primary key never leaves the server.
     */
    id: string;
    type: ConversationType;
    /** Group conversations may be named; direct ones never are. */
    name: string | null;
    /** The group's name, or the other participants' names. */
    display_name: string;
    /** The current roster, the viewer included. */
    participants: SnackUser[];
    last_message_at: string | null;
    is_muted: boolean;
    notification_level: NotificationLevel | null;
    last_read_message_id: number | null;
    /** Left a group: history stays readable, the composer does not render. */
    has_left: boolean;
    created_at: string | null;
};

// -- Huddles -----------------------------------------------------------------

/**
 * Where a huddle is happening. Mirrors the `container` key of
 * App\Http\Resources\HuddleResource, which is the same shape
 * App\Http\Resources\ContainerSummary uses — one shape for "where does this
 * live" so the client only ever learns one.
 */
export type HuddleContainerRef =
    | { kind: 'channel'; id: number }
    /** The conversation's opaque public id, never its primary key. */
    | { kind: 'conversation'; id: string };

/**
 * Mirrors App\Http\Resources\HuddleResource.
 *
 * Deliberately viewer-independent: the identical shape is the body of the
 * `huddle.changed` broadcast, where there is no viewer at all. Whether *you*
 * are in the huddle is something the client knows from its own connection
 * state, so it is not carried here.
 */
export type Huddle = {
    /** An opaque ULID, and also the LiveKit room name. */
    id: string;
    container: HuddleContainerRef;
    started_at: string | null;
    ended_at: string | null;
    is_live: boolean;
    /**
     * The roster as the *server* knows it, which is what someone who has not
     * joined sees in the huddle bar. Once connected, tiles are rendered from
     * LiveKit's own participant list instead — that one is authoritative for who
     * can currently be heard and updates without a round trip.
     */
    participants: SnackUser[];
};

/**
 * Mirrors App\Services\LiveKit\HuddleConnection: everything the browser needs to
 * dial in.
 *
 * `token` is a credential. It arrives only in the response to your own join
 * request and never appears in a broadcast, which is why it is not part of
 * {@see Huddle}.
 */
/**
 * One row of the huddle history.
 *
 * Deliberately not {@see Huddle}: that shape is what a *live* huddle broadcasts,
 * and carries who is in the room right now. This is the record of a call that
 * has finished — everyone has left one of those — so `participants` is everyone
 * who was ever on it, and `duration_seconds` is computed by the server rather
 * than from two timestamps and the reader's own clock.
 */
export type HuddleHistoryEntry = {
    id: string;
    /** Where the call happened, in the shape every out-of-context list uses. */
    container: ThreadContainer | null;
    started_at: string;
    ended_at: string | null;
    is_live: boolean;
    /** Null while it is still running, and therefore still accruing one. */
    duration_seconds: number | null;
    started_by: SnackUser | null;
    participants: SnackUser[];
};

export type HuddleConnection = {
    /** The LiveKit signalling URL. Sent per response so the media server can
     *  move without a rebuilt bundle. */
    url: string;
    token: string;
    /** The LiveKit identity this token was minted for, used to tell your own
     *  tile apart from everyone else's. */
    identity: string;
};

/** The join endpoints' response body. */
export type HuddleJoinResponse = {
    huddle: Huddle;
    connection: HuddleConnection;
};

// -- Shared Inertia props ----------------------------------------------------

/**
 * Unread state for the sidebar badges.
 *
 * `channels` is keyed by channel id. Counts are derived from last-read
 * pointers rather than per-message read receipts, so this stays small no
 * matter how long the history is.
 */
export type UnreadState = {
    channels: Record<number, UnreadCounts>;
    /**
     * Keyed by conversation id. `mentions` tracks `messages` here rather than
     * staying at zero: everything said in a conversation you are in is addressed
     * to you, so the sidebar treats it as loudly as a real @-mention.
     */
    conversations: Record<string, UnreadCounts>;
    mentions: number;
    threads: number;
    /**
     * The notification centre's badge. Not a sum of the two above: those count
     * what is waiting inside a container, this counts what has been raised to
     * you, and the two diverge as soon as a mention is read from the channel
     * without its notification being opened.
     */
    notifications: number;
};

export type UnreadCounts = {
    messages: number;
    mentions: number;
};

/**
 * One row of the sidebar's direct message list: a person, and the one-to-one
 * conversation with them if it exists yet.
 *
 * Mirrors an entry of App\Services\WorkspaceNavigator::sidebarPeople(). A null
 * `conversation` is the normal state for most of the workspace — the thread is
 * created by the click that opens it, not by rendering the list.
 */
export type WorkspacePerson = {
    user: SnackUser;
    conversation: Conversation | null;
};

/** The navigation payload for the workspace the user is currently inside. */
export type CurrentWorkspace = {
    workspace: Workspace;
    channels: Channel[];
    /**
     * Every conversation the viewer is in, direct and group alike. The sidebar
     * renders the groups from here and the direct ones through `people`, but
     * this stays the complete set: huddles, container labels, and unread
     * lookups all resolve a conversation by id against it.
     */
    conversations: Conversation[];
    /** Everyone else in the workspace, which is what the DM list is built from. */
    people: WorkspacePerson[];
    unread: UnreadState;
};

/** Shared on every page as the `snack` prop. */
export type SnackProps = {
    workspaces: WorkspaceSummary[];
    /** Null outside a workspace-scoped route, e.g. on settings or auth pages. */
    current: CurrentWorkspace | null;
};

export type FlashMessages = {
    success: string | null;
    error: string | null;
    /**
     * True when the server chose the room on screen — the redirect out of the
     * workspace root — rather than the reader asking for it by URL.
     */
    entered_workspace: boolean;
};

// -- Real-time payloads ------------------------------------------------------

/** The member shape published by the presence-channel authorization callbacks. */
export type PresenceMember = {
    id: number;
    name: string;
    username: string;
    avatar_url: string | null;
    presence_status?: PresenceStatus;
    status_emoji?: string | null;
};

/** Payload of the `channel.created` broadcast. */
export type ChannelCreatedEvent = {
    channel: Pick<
        Channel,
        | 'id'
        | 'name'
        | 'slug'
        | 'description'
        | 'type'
        | 'is_private'
        | 'is_archived'
    > & { created_by: number | null };
};

/** Payload of the `message.sent` and `message.updated` broadcasts. */
export type MessageEvent = {
    message: Omit<Message, 'can'>;
};

/** Payload of the `message.deleted` broadcast. */
export type MessageDeletedEvent = {
    id: number;
    channel_id: number | null;
    conversation_id: string | null;
    parent_message_id: number | null;
};

/**
 * Payload of the `pin.changed` broadcast.
 *
 * Carries the message itself, unlike `thread.activity`: the audience is exactly
 * the people who can already see it in the timeline, so the body is not new
 * information to any of them — and the pinned bar can redraw without a
 * follow-up request.
 */
export type PinChangedEvent = {
    pinned: boolean;
    message: Omit<Message, 'can' | 'is_saved'>;
};

/**
 * One row in the Saved view.
 */
export type SavedSummary = {
    id: number;
    notes: string | null;
    saved_at: string | null;
    message: Message;
    container: ThreadContainer | null;
};

/**
 * Payload of the `reaction.changed` broadcast.
 *
 * The message's *complete* reaction state, not a delta. A client that missed one
 * broadcast — a reconnect, a backgrounded tab — would carry that drift until it
 * reloaded; a full summary is self-correcting.
 */
export type ReactionChangedEvent = {
    message_id: number;
    reactions: ReactionGroup[];
};

/**
 * Payload of the `thread.activity` broadcast.
 *
 * Sent to the *container*, not the thread: the audience is everyone looking at
 * the channel, whose copy of the parent shows "3 replies" and needs to say four.
 * Deliberately carries no reply body — it reaches every member of the channel,
 * including everyone who never opens the thread.
 */
export type ThreadActivityEvent = {
    root_id: number;
    reply_count: number;
    last_reply_at: string | null;
};

/** Payload of the `channel.read` broadcast, for syncing a user's own devices. */
export type ChannelReadEvent = {
    channel_id: number;
    last_read_message_id: number | null;
};

/**
 * Payload of the `conversation.started` and `conversation.activity` broadcasts,
 * both delivered to a participant's own private channel.
 *
 * Deliberately just an id. These reach people who are *not* looking at the
 * conversation, so they carry no message content — and the client re-reads its
 * counts from the server rather than trusting a number off the wire.
 */
export type ConversationSignalEvent = {
    conversation_id: string;
};

/**
 * Payload of the `channel.activity` broadcast — the channel counterpart of
 * {@see ConversationSignalEvent}. The id and nothing else: the client re-reads
 * its unread counts rather than trusting a number off the wire.
 */
export type ChannelSignalEvent = {
    channel_id: number;
};

/**
 * Payload of the `presence.changed` broadcast.
 *
 * Carries the id and the new status and nothing else: it fans out to every
 * member of the workspace, so it can say only what everyone there is already
 * entitled to see.
 */
export type PresenceChangedEvent = {
    user_id: number;
    presence_status: PresenceStatus;
};

/** Payload of the `conversation.read` broadcast, for a user's own devices. */
export type ConversationReadEvent = {
    conversation_id: string;
    last_read_message_id: number | null;
};

/**
 * Payload of the `conversation.updated` broadcast: a rename, or a change to who
 * is in the conversation.
 *
 * Omits `display_name` because one broadcast reaches every subscriber and that
 * field differs per viewer. The client derives its own.
 */
export type ConversationUpdatedEvent = {
    conversation: Pick<Conversation, 'id' | 'type' | 'name' | 'participants'>;
};

/**
 * Payload of the `huddle.changed` broadcast, on a container's presence channel.
 *
 * Carries the whole huddle every time rather than a delta. WebSocket delivery
 * guarantees neither order nor arrival, and one dropped "left" frame under a
 * delta scheme leaves somebody shown in a call they walked out of, with nothing
 * to correct it until a reload. Every frame here is independently sufficient and
 * the newest one wins.
 *
 * `huddle` is null when the container was deleted out from under the call.
 */
export type HuddleChangedEvent = {
    huddle: Huddle | null;
};

/**
 * Payload of the `huddle.activity` broadcast, on a member's own private channel.
 *
 * The sidebar dot. Fires only when a huddle starts or ends — not on every join —
 * because the recipients are by definition looking at something else, and a
 * fourth person walking into a call elsewhere is not news. No roster for the
 * same reason.
 */
export type HuddleActivityEvent = {
    huddle_id: string;
    container: HuddleContainerRef;
    is_live: boolean;
};

/** Payload of the `workspace.member_joined` broadcast. */
export type WorkspaceMemberJoinedEvent = {
    member: {
        id: number;
        role: WorkspaceRole;
        joined_at: string | null;
        user: Pick<
            SnackUser,
            'id' | 'name' | 'username' | 'avatar_url' | 'title'
        >;
    };
};
