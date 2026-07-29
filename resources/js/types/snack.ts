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
 * Direct and group conversations. Populated in Phase 3; the type is declared
 * now so the sidebar's contract does not change when it lands.
 */
export type Conversation = {
    id: number;
    type: ConversationType;
    name: string | null;
    display_name: string;
    participants: SnackUser[];
    last_message_at: string | null;
    is_muted: boolean;
    last_read_message_id: number | null;
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
    channels: Record<number, { messages: number; mentions: number }>;
    mentions: number;
    threads: number;
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
