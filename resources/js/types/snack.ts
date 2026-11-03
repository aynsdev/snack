import type { JSONContent } from '@tiptap/core';

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
export type ConversationType = 'direct' | 'group' | 'bite';

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
export type NotificationReason =
    | 'mention'
    | 'direct_message'
    | 'thread_reply'
    // The one reason that is not about a message: somebody put a task on a
    // project board in your name.
    | 'task_assigned';

/**
 * Mirrors App\Enums\ActivityTab: which slice of the Activity page is open.
 *
 * `all` is the three lists interleaved by time rather than a fourth list of its
 * own, which is why the page can render every tab from the same rows.
 */
export type ActivityTab =
    'all' | 'unread' | 'notifications' | 'mentions' | 'threads';

/** Mirrors App\Enums\ProjectRole. */
export type ProjectRole = 'lead' | 'member';

/** Mirrors App\Enums\TaskType. */
export type TaskType = 'story' | 'task' | 'bug' | 'epic' | 'subtask';

/** Mirrors App\Enums\TaskPriority. */
export type TaskPriority = 'highest' | 'high' | 'medium' | 'low' | 'lowest';

/**
 * Mirrors App\Enums\ColumnCategory: what a column *means*, as opposed to what a
 * team called it.
 *
 * A project names its own columns, so nothing may infer status from a name.
 * This is what says which column new work lands in and which ones count as
 * finished when a sprint closes.
 */
export type ColumnCategory = 'unstarted' | 'started' | 'done';

/**
 * Mirrors App\Enums\SprintState, which is derived from the sprint's timestamps
 * rather than stored — see App\Models\Sprint::state().
 */
export type SprintState = 'planned' | 'active' | 'completed';

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
    create_projects: boolean;
    start_conversations: boolean;
    mention_everyone: boolean;
    manage_people: boolean;
    view_audit_logs: boolean;
    is_guest: boolean;
    /** Bite is on here and this person is in its audience. */
    use_bite: boolean;
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
    /**
     * The section of *this viewer's* sidebar the channel is filed under; null
     * when they have filed it nowhere, and for a channel they have not joined.
     */
    channel_group_id: number | null;
    notification_level: NotificationLevel | null;
    last_read_message_id: number | null;
    created_at: string | null;
};

/** One person in a channel, keyed by the membership row the routes address. */
export type ChannelMembership = {
    id: number;
    user: SnackUser;
    role: ChannelRole;
    joined_at: string | null;
};

/**
 * A section of the viewer's channel list.
 *
 * Mirrors App\Http\Resources\ChannelGroupResource. Carries no channels of its
 * own: the payload sends every joined channel once, each naming its section by
 * id.
 *
 * Unlike ProjectGroup, this belongs to one person — the sidebar lists the
 * channels *you* joined, so its arrangement can only be yours.
 */
export type ChannelGroup = {
    id: number;
    name: string;
    position: number;
};

/** The `agent` block on a message an agent sent — see `Message.agent`. */
export type MessageAgent = {
    /** The connector's name, as its owner called it. */
    name: string;
    kind?: 'root' | 'plan' | 'reply' | 'notice' | null;
    task_id?: number | null;
    /** On a plan only: who may approve or cancel it. */
    task_assignee_id?: number | null;
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
     * Present only on the standup bot's prompts and reminders, which is a
     * handful of messages in one conversation — null on everything else.
     *
     * Carried by the message rather than fetched, so a DM with a month of
     * standups in it draws every card without a request per message, and the
     * card survives a reload. Rewritten in place when the response is
     * submitted, which is what retires the form.
     */
    standup?: StandupCard | null;
    /**
     * Present only on messages an outside system posted through an incoming
     * webhook — null on everything else.
     *
     * Carried by the message for the reason `standup` is: the cards were built
     * at delivery time from a payload that exists nowhere else, so a channel of
     * build notifications draws every one of them without a request each.
     *
     * It is a *snapshot*. The hook may have been renamed or revoked since, and a
     * year-old deploy notice should still say which system sent it — so
     * `username` here wins over the sender's current name.
     */
    webhook?: WebhookMessage | null;
    /**
     * Present only on a Bite reply that is waiting to be approved — null on
     * everything else, including all of Bite's ordinary answers.
     *
     * Carried by the message for the reason `standup` is, plus one of its own:
     * the paused tool call it refers to lives in the AI SDK's own tables, and
     * resolving it per message would mean querying those for every row in the
     * timeline. Rewritten in place when the decision is made.
     */
    bite?: BiteCard | null;
    /**
     * Present when a person's agent posted this under their name — null on
     * everything else. The header says so, so an agent's words are never
     * mistaken for its owner's.
     */
    agent?: MessageAgent | null;
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
    biteModel?: string | null;
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
    /**
     * Set only on a task assignment, which is the one reason that is not about a
     * message and so has no container to link through — the row builds its own
     * link to the board from this.
     */
    task: NotificationTask | null;
};

/** Where an assignment notification points. */
export type NotificationTask = {
    id: number;
    reference: string;
    title: string;
    project_id: number;
    project_name: string;
    project_slug: string;
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
    /**
     * Where the inline player streams a video from, with range support. Null
     * for anything that is not a browser-playable video.
     */
    stream_url: string | null;
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

// -- Asset library -----------------------------------------------------------

/**
 * A shelf in the asset library. Mirrors App\Http\Resources\AssetFolderResource.
 *
 * Sent flat, each node naming its parent, and assembled into a tree by the
 * client — the move dialog wants the flat list anyway, and nesting server-side
 * would mean sending every folder twice.
 *
 * The two counts are direct children only, which is what a tile says ("4 folders
 * · 12 files") without the page having loaded any of them.
 */
export type AssetFolder = {
    id: number;
    parent_id: number | null;
    name: string;
    description: string | null;
    folder_count: number;
    asset_count: number;
    /** Rename, re-describe, move. The creator's right, or an admin's. */
    can_manage: boolean;
    /**
     * Narrower than `can_manage`: a folder with anything in it takes other
     * people's work with it, so only an admin may delete one.
     */
    can_delete: boolean;
};

/**
 * Something on a shelf. Mirrors App\Http\Resources\AssetResource.
 *
 * `name` is what the library calls it; `file.name` is what was uploaded. They
 * are usually different, and both are shown — the second is how somebody checks
 * they are about to download the right thing.
 *
 * `file` is null only for an entry whose bytes are gone, which nothing normal
 * produces. It renders as a broken tile rather than crashing the gallery.
 */
export type LibraryAsset = {
    id: number;
    folder_id: number | null;
    name: string;
    description: string | null;
    file: Attachment | null;
    creator: SnackUser | null;
    created_at: string | null;
    can_manage: boolean;
};

// -- Canvases ---------------------------------------------------------------

/** A shared workspace document. Mirrors App\Http\Resources\CanvasResource. */
export type CanvasDocument = {
    id: string;
    title: string;
    /** Present on the document page, omitted from the browse payload. */
    body?: string;
    content?: JSONContent | null;
    /** Present on the document page, omitted from the browse payload. */
    cover_url?: string | null;
    excerpt: string;
    creator: SnackUser | null;
    editor: SnackUser | null;
    created_at: string | null;
    updated_at: string | null;
    can_update: boolean;
    can_delete: boolean;
    is_starred: boolean;
    container?: {
        type: 'channel' | 'conversation';
        label: string;
        url: string;
    } | null;
};

export type FileCanvasSidebarItem =
    | {
          id: string;
          target_id: number;
          type: 'file';
          label: string;
          is_starred: boolean;
          file: FileRow;
      }
    | {
          id: string;
          target_id: string;
          type: 'canvas';
          label: string;
          url: string;
          is_starred: boolean;
      };

export type FileCanvasSidebarContent = {
    recent: FileCanvasSidebarItem[];
    starred: FileCanvasSidebarItem[];
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
    is_bot: boolean;
    conversation: Conversation | null;
};

// -- Projects ----------------------------------------------------------------

/**
 * Which parts of a task the board draws on its cards.
 *
 * Mirrors App\Models\Project::CARD_FIELD_DEFAULTS, and always arrives resolved —
 * the server fills in defaults for anything the project has not decided, so the
 * client never has to treat a missing key as a third state.
 */
export type CardFields = {
    type: boolean;
    key: boolean;
    priority: boolean;
    assignee: boolean;
    labels: boolean;
    story_points: boolean;
    due_on: boolean;
    epic: boolean;
};

/**
 * Mirrors App\Http\Resources\ProjectResource.
 *
 * `abilities` is the split App\Policies\ProjectPolicy makes: anyone who is not a
 * guest can read a board, and only the people on the project can work it. The
 * flags decide what to render; the server re-authorizes every write regardless.
 */
export type Project = {
    id: number;
    name: string;
    slug: string;
    /** The prefix of every reference in this project — the "PWA" in PWA-1499. */
    key: string;
    description: string | null;
    /** Normalised `https://github.com/<owner>/<repo>`, or null. */
    repository_url: string | null;
    /** The branch agents target; null means the repository's default. */
    repository_base_branch: string | null;
    color: string;
    uses_sprints: boolean;
    is_archived: boolean;
    /** The number the next card filed here will carry — the 1500 after PWA-1499. */
    next_task_number: number;
    /** The heading this project sits under; null when it sits under none. */
    project_group_id: number | null;
    card_fields: CardFields;
    /**
     * Where the project's agents talk. Absent for viewers who cannot manage
     * the project; agents are on while it is set and neither it nor the
     * project is archived.
     */
    agent_channel_id?: number | null;
    /** The column an agent moves a card into when it starts work. */
    agent_in_progress_column_id?: number | null;
    /** The column an agent moves a card into when its pull request is up. */
    agent_review_column_id?: number | null;
    /** Whether the task panel offers its agent controls. */
    agents_enabled: boolean;
    member_count?: number;
    task_count?: number;
    is_member: boolean;
    /** Whether *this viewer* keeps it at the top of their sidebar. */
    is_starred: boolean;
    abilities: {
        contribute: boolean;
        manage: boolean;
        /** Can put themselves on it — false when already on, or a guest. */
        join: boolean;
        /** Can take themselves off it. */
        leave: boolean;
    };
    created_at: string | null;
};

/**
 * A heading the project list is arranged under.
 *
 * Mirrors App\Http\Resources\ProjectGroupResource. Carries no projects of its
 * own: the list sends every project once, each naming its group by id.
 */
export type ProjectGroup = {
    id: number;
    name: string;
    position: number;
};

/** Mirrors App\Http\Resources\BoardColumnResource. */
export type BoardColumn = {
    id: number;
    name: string;
    category: ColumnCategory;
    position: number;
    /** Advisory: the board warns when a column is over it, never refuses a drop. */
    wip_limit: number | null;
};

/** A label one project has defined, as it arrives inside a task or a board. */
export type TaskLabel = {
    id: number;
    name: string;
    color: string;
};

/** Mirrors App\Enums\TaskAgentIntent: the `agent` field of a task update. */
export type TaskAgentIntent = 'assign' | 'start' | 'stop';

/**
 * Mirrors App\Http\Resources\SprintResource.
 *
 * The two pairs of dates are different claims: `starts_at`/`ends_at` are what
 * the team planned, `started_at`/`completed_at` are what happened. `state` is
 * derived from the second pair server-side and is the only place to read it.
 */
export type Sprint = {
    id: number;
    name: string;
    goal: string | null;
    state: SprintState;
    is_overdue: boolean;
    starts_at: string | null;
    ends_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    position: number;
    task_count?: number;
};

/**
 * Mirrors App\Http\Resources\TaskResource.
 *
 * The same shape whether it arrives as a page prop or over the socket, so a
 * live update is stored exactly as it is received — see App\Events\TaskChanged
 * for why the whole card travels every time rather than a diff.
 *
 * `board_column_id` is where the card sits on the board and is never null;
 * `sprint_id` is when it is being done, and null means the backlog; `release`
 * is what it ships in, which is a third, independent question.
 */
export type Task = {
    id: number;
    number: number;
    /** "PWA-1499" — what people quote, and what a board link resolves. */
    reference: string;
    title: string;
    description: string | null;
    type: TaskType;
    priority: TaskPriority;
    /**
     * "feature/PWA-1499-cancel-a-ride" — composed by the server so every
     * surface that offers to copy one offers the same one.
     */
    branch_name: string;
    project_id: number;
    board_column_id: number;
    sprint_id: number | null;
    position: number;
    story_points: number | null;
    due_on: string | null;
    completed_at: string | null;
    is_complete: boolean;
    assignee?: SnackUser | null;
    labels?: TaskLabel[];
    epic?: { id: number; title: string; reference: string } | null;
    release?: { id: number; name: string } | null;
    /**
     * The discussion and the files, which arrive only from `tasks.show` — a
     * board of eighty cards never carries either.
     */
    comments?: TaskComment[];
    attachments?: Attachment[];
    /** Subtasks done and in all, for the card's badge; null when it has none. */
    subtask_progress?: { done: number; total: number } | null;
    /** The subtasks themselves — only from `tasks.show`. */
    subtasks?: TaskSubtask[];
    /** Web links and linked cards — also only from `tasks.show`. */
    links?: TaskLink[];
    relations?: TaskRelation[];
    created_at: string | null;
    updated_at: string | null;
};

/** One subtask as the parent's panel lists it. */
export type TaskSubtask = {
    id: number;
    reference: string;
    title: string;
    type: TaskType;
    status: string;
    is_complete: boolean;
    assignee: SnackUser | null;
};

/** A web link on a card, such as a pull request. */
export type TaskLink = {
    id: number;
    url: string;
    title: string | null;
    /** The title, or the URL without its scheme. */
    label: string;
};

export type TaskRelationType = 'blocks' | 'relates_to' | 'duplicates';

/**
 * Another card this one is linked to, worded from this card's side —
 * "blocks", "is blocked by", "relates to", "duplicates", "is duplicated by".
 */
export type TaskRelation = {
    id: number;
    type: TaskRelationType;
    wording: string;
    task: {
        id: number;
        reference: string;
        title: string;
        status: string;
        is_complete: boolean;
    };
};

/** A card offered by the parent and link pickers. */
export type TaskPickerOption = {
    id: number;
    reference: string;
    title: string;
    type: TaskType;
};

/**
 * One remark on a card. Mirrors App\Http\Resources\TaskCommentResource.
 *
 * `author` is null when the person who wrote it has been deleted — the remark
 * outlives them, because the reasoning recorded on a card is what the next
 * person needs.
 */
export type TaskComment = {
    id: number;
    body: string;
    /** Set only by a rewrite, which is what the "edited" mark means. */
    edited_at: string | null;
    created_at: string | null;
    author: SnackUser | null;
    /** Set when the author's agent wrote it. */
    agent: { name: string } | null;
    /** The policy's answer, not the client's guess — editing and deleting
     *  have different rules. */
    can: { update: boolean; delete: boolean };
};

/** One person on a project, keyed by the membership row the routes address. */
export type ProjectMembership = {
    id: number;
    user: SnackUser;
    role: ProjectRole;
    joined_at: string | null;
};

/**
 * Mirrors App\Http\Resources\ReleaseResource.
 *
 * A version of the project and the work going out in it. `tasks` arrives with
 * the releases page and is absent everywhere a release is only being named.
 */
export type Release = {
    id: number;
    name: string;
    notes: string | null;
    starts_on: string | null;
    ends_on: string | null;
    released_at: string | null;
    /** Derived from `released_at`; there is no status column to disagree. */
    state: ReleaseState;
    /** Its day has passed and it has not gone out. */
    is_overdue: boolean;
    /** Everything scheduled into it, and how much of that is finished. */
    task_count: number;
    completed_count: number;
    /** Only with the release's own page — the table sends counts, not cards. */
    tasks?: ReleaseTask[];
    created_at: string | null;
};

/** Mirrors App\Enums\ReleaseState. */
export type ReleaseState = 'unreleased' | 'released';

/** One card as a release lists it — a checklist row, not a board card. */
export type ReleaseTask = {
    id: number;
    reference: string;
    title: string;
    type: TaskType;
    is_complete: boolean;
};

/**
 * The project summary's payloads — mirrors Projects\ProjectSummaryController.
 *
 * Counts rather than cards: every panel there is an aggregate the database did,
 * and a board of a thousand tasks must not be serialized to draw five bars.
 */
export type SummaryStatus = {
    id: number;
    name: string;
    category: ColumnCategory;
    count: number;
};

/** One bar of the priority or type breakdown. */
export type SummaryCount = {
    value: string;
    label: string;
    count: number;
};

/** One line of the summary's activity list. */
export type SummaryActivity = {
    id: number;
    reference: string;
    title: string;
    type: TaskType;
    /** The column it is sitting in now, by name. */
    status: string;
    is_complete: boolean;
    assignee: string | null;
    updated_at: string | null;
};

/** A release as the picker on a card offers it. */
export type ReleaseOption = {
    id: number;
    name: string;
};

/** What arrives over `project.{id}` when one card changes. */
export type TaskChangedEvent = {
    action: 'created' | 'updated' | 'moved' | 'deleted';
    task_id: number;
    reference: string;
    /** Null for a delete, which has no state left to send. */
    task: Task | null;
};

/** The navigation payload for the workspace the user is currently inside. */
export type CurrentWorkspace = {
    workspace: Workspace;
    channels: Channel[];
    /**
     * The headings `channels` is drawn under — the viewer's own sections, empty
     * ones included. The sidebar is where they are made and filled, so a
     * section that has just been emptied has to stay on screen.
     */
    channel_groups: ChannelGroup[];
    /**
     * Every conversation the viewer is in, direct and group alike. The sidebar
     * renders the groups from here and the direct ones through `people`, but
     * this stays the complete set: huddles, container labels, and unread
     * lookups all resolve a conversation by id against it.
     */
    conversations: Conversation[];
    /** Everyone else in the workspace, which is what the DM list is built from. */
    people: WorkspacePerson[];
    /**
     * The projects the viewer is on — not every project in the workspace. See
     * App\Services\WorkspaceNavigator::memberProjects() for why the sidebar
     * lists membership and the project page lists everything.
     */
    projects: Project[];
    /**
     * The headings `projects` is drawn under — only the groups with one of the
     * viewer's projects in them, so the sidebar never shows an empty heading.
     * The project page, where groups are curated, does show empty ones.
     */
    project_groups: ProjectGroup[];
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

// -- AI connectors -----------------------------------------------------------

/** An app that connected to the MCP server by signing in. */
export type ConnectedApp = {
    id: number;
    name: string;
    connected_at: string | null;
};

/**
 * What a personal connector is for — mirrors App\Enums\ConnectorKind. An agent
 * has the same access as a token; its messages are labelled as an agent's.
 */
export type ConnectorKind = 'token' | 'agent';

/** One of the current person's personal MCP tokens. Never carries the secret. */
export type ConnectorToken = {
    id: string;
    name: string;
    kind: ConnectorKind;
    created_at: string | null;
    expires_at: string | null;
};

/**
 * A just-issued connector, flashed on the response that created it. The only
 * time the plaintext token ever reaches the browser.
 */
export type ConnectorFlash = {
    id: string;
    name: string;
    kind: ConnectorKind;
    token: string;
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

/*
|--------------------------------------------------------------------------
| Standups
|--------------------------------------------------------------------------
*/

/** Waiting on them, answered, or "nothing from me today". */
export type StandupResponseState = 'pending' | 'submitted' | 'skipped';

/** Taking answers, or written up and posted. */
export type StandupRunState = 'collecting' | 'completed';

/**
 * The block a standup direct message carries — mirrors App\Support\StandupCard.
 *
 * `questions` is the snapshot of what was actually asked, not the standup's
 * current wording: an admin rewording a question must not silently refile
 * yesterday's answers under today's questions. The form posts its answers back
 * positionally against this list.
 */
export type StandupCard = {
    standup_id: number;
    run_id: number;
    response_id: number;
    name: string;
    channel: { name: string; slug: string };
    /** The local date the run belongs to, as the team would name the day. */
    date: string;
    date_label: string;
    questions: string[];
    closes_at: string;
    state: StandupResponseState;
    submitted_at: string | null;
    /** Present once answered — what the card shows in place of the form. */
    answers?: StandupAnswer[];
};

export type StandupAnswer = {
    question: string;
    answer: string;
};

/** Waiting on a decision, or decided one way or the other. */
export type BiteCardState = 'pending' | 'approved' | 'rejected';

/**
 * The block one of Bite's messages carries when it has paused — mirrors
 * App\Support\BiteCard.
 *
 * Bite reads freely but asks before it writes, so this appears on exactly one
 * kind of message: a reply where it wants to create something and is waiting to
 * be told to go ahead.
 *
 * `approvals` is emptied once decided. The ids in it address paused tool calls
 * that the server has since consumed, and leaving them would invite a second
 * POST that can only be an error.
 */
export type BiteCard = {
    /** The stored agent conversation to resume into. */
    conversation_id: string;
    state: BiteCardState;
    approvals: BiteApproval[];
    /** Present once decided, when there was anything to report. */
    outcome?: string | null;
};

/**
 * One thing Bite is asking permission for.
 *
 * `summary` is the sentence to show. It is written by the tool itself — see
 * App\Ai\Tools\CreateTask — precisely so the person can check the model got the
 * details right before agreeing, which is the entire point of the pause. The
 * raw arguments are deliberately not sent: nothing the browser returns can
 * change what actually runs.
 */
export type BiteApproval = {
    id: string;
    tool: string;
    reason: string | null;
    summary: string;
};

/**
 * A standup as the admin screen sees it — mirrors
 * App\Http\Resources\StandupResource.
 *
 * `schedule_summary` arrives rendered rather than assembled here: the client
 * would need the same weekday names and the same "Weekdays"/"Every day"
 * collapsing to say the same sentence, and two implementations of one sentence
 * is how the list and the dialog end up describing a standup differently.
 */
export type Standup = {
    id: number;
    name: string;
    channel: { id: number; name: string; slug: string };
    questions: string[];
    /** ISO weekday numbers, 1 = Monday. */
    days_of_week: number[];
    /** "09:30", in `timezone`. */
    ask_at: string;
    timezone: string;
    reminder_after_minutes: number | null;
    closes_after_minutes: number;
    is_active: boolean;
    /** Null asks the whole channel; a list asks only those people still in it. */
    participant_ids: number[] | null;
    schedule_summary: string;
    /** Who this actually messages — the channel, narrowed by `participant_ids`. */
    participant_count?: number;
    last_run: StandupLastRun | null;
    created_at: string | null;
};

export type StandupLastRun = {
    date: string;
    date_label: string;
    state: StandupRunState;
    asked_at: string | null;
    closed_at: string | null;
    answered: number;
    asked: number;
};

/**
 * The block a message posted through an incoming webhook carries — mirrors the
 * `webhook` key App\Support\WebhookPayload writes into `messages.metadata`.
 *
 * Every field here came from an unauthenticated request. It is safe to render
 * because the server rebuilt the whole structure key by key and dropped what it
 * did not recognise — see that class — so there is nothing in here the caller
 * chose the *shape* of. Render it as text and attributes all the same; nothing
 * in it goes near `dangerouslySetInnerHTML`, which the app does not use at all.
 */
export type WebhookMessage = {
    webhook_id: number;
    /** Who the message is attributed to, snapshotted at delivery. */
    username: string;
    /** An emoji shortcode without its colons, or null. */
    icon: string | null;
    attachments: WebhookAttachment[];
};

/**
 * One card beneath a webhook message — Slack's legacy attachment shape, which
 * is what existing integrations already emit.
 *
 * Almost every field is optional because almost every caller sends a different
 * three of them.
 */
export type WebhookAttachment = {
    /** 'good' | 'warning' | 'danger' | 'default', or a `#rrggbb` triple. */
    color?: string;
    pretext?: string;
    author_name?: string;
    title?: string;
    /** Always http or https — other schemes are dropped server-side. */
    title_link?: string;
    text?: string;
    footer?: string;
    /** Unix seconds, rendered in the reader's own locale. */
    ts?: number;
    fields?: WebhookField[];
};

export type WebhookField = {
    title: string;
    value: string;
    /** Slack's flag for a half-width field. */
    short: boolean;
};

/**
 * An incoming webhook as the admin screen sees it — mirrors
 * App\Http\Resources\IncomingWebhookResource.
 *
 * `url` carries a live credential and is null once the hook is revoked. The
 * screen that renders it is admin-gated for that reason rather than a
 * presentational one.
 */
export type IncomingWebhook = {
    id: number;
    name: string;
    channel: { id: number; name: string; slug: string };
    default_username: string | null;
    default_icon: string | null;
    /** What its messages are actually attributed to. */
    display_name: string;
    url: string | null;
    /**
     * Whether deliveries must carry a valid signature. Off by default — the URL
     * alone is the credential until somebody turns this on.
     */
    signing_required: boolean;
    /**
     * The signing secret in plaintext, on the same terms as `url`: there is
     * nowhere else it could come from, and the policy gating this screen is what
     * makes showing it acceptable. Null when signing is off, and null once the
     * hook is revoked.
     */
    signing_secret: string | null;
    is_revoked: boolean;
    revoked_at: string | null;
    delivery_count: number;
    last_delivered_at: string | null;
    created_by?: { id: number; name: string } | null;
    created_at: string | null;
};
