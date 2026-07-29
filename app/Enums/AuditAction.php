<?php

namespace App\Enums;

/**
 * Every workspace mutation worth reconstructing after the fact.
 *
 * Values are namespaced `subject.verb` so the audit log can be filtered by
 * prefix in the admin UI.
 */
enum AuditAction: string
{
    case WorkspaceCreated = 'workspace.created';
    case WorkspaceUpdated = 'workspace.updated';
    case WorkspaceSettingsUpdated = 'workspace.settings_updated';

    case MemberInvited = 'member.invited';
    case MemberInvitationRevoked = 'member.invitation_revoked';
    case MemberJoined = 'member.joined';
    case MemberRemoved = 'member.removed';
    case MemberSuspended = 'member.suspended';
    case MemberReinstated = 'member.reinstated';
    case MemberRoleChanged = 'member.role_changed';

    case ChannelCreated = 'channel.created';
    case ChannelUpdated = 'channel.updated';
    case ChannelArchived = 'channel.archived';
    case ChannelUnarchived = 'channel.unarchived';
    case ChannelMemberAdded = 'channel.member_added';
    case ChannelMemberRemoved = 'channel.member_removed';

    case MessageDeletedByAdmin = 'message.deleted_by_admin';

    public function label(): string
    {
        return match ($this) {
            self::WorkspaceCreated => 'Workspace created',
            self::WorkspaceUpdated => 'Workspace updated',
            self::WorkspaceSettingsUpdated => 'Workspace settings updated',
            self::MemberInvited => 'Member invited',
            self::MemberInvitationRevoked => 'Invitation revoked',
            self::MemberJoined => 'Member joined',
            self::MemberRemoved => 'Member removed',
            self::MemberSuspended => 'Member suspended',
            self::MemberReinstated => 'Member reinstated',
            self::MemberRoleChanged => 'Member role changed',
            self::ChannelCreated => 'Channel created',
            self::ChannelUpdated => 'Channel updated',
            self::ChannelArchived => 'Channel archived',
            self::ChannelUnarchived => 'Channel unarchived',
            self::ChannelMemberAdded => 'Added to channel',
            self::ChannelMemberRemoved => 'Removed from channel',
            self::MessageDeletedByAdmin => 'Message removed by admin',
        };
    }

    /**
     * The `subject` portion of the value, used for grouping and filtering.
     */
    public function subject(): string
    {
        return str($this->value)->before('.')->toString();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
