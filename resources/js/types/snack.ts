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

/** Mirrors App\Enums\PresenceStatus. */
export type PresenceStatus = 'online' | 'away' | 'offline' | 'do_not_disturb';

/** Lifecycle of a workspace invitation, from WorkspaceInvitation::status(). */
export type InvitationStatus = 'pending' | 'accepted' | 'revoked' | 'expired';

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
    has_attachments: boolean;
    is_edited: boolean;
    edited_at: string | null;
    created_at: string | null;
    /**
     * Absent on messages that arrived over the WebSocket: one broadcast reaches
     * every subscriber, so it cannot carry per-viewer permissions. The timeline
     * fills in a local hint for those; the server re-authorizes regardless.
     */
    can?: MessageAbilities;
};

export type MessageAbilities = {
    update: boolean;
    delete: boolean;
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
};

/** One page of cursor-paginated history. */
export type MessagePage = {
    messages: Message[];
    has_more: boolean;
    next_cursor: number | null;
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
    email: string;
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
};

export type UnreadCounts = {
    messages: number;
    mentions: number;
};

/** The navigation payload for the workspace the user is currently inside. */
export type CurrentWorkspace = {
    workspace: Workspace;
    channels: Channel[];
    conversations: Conversation[];
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
