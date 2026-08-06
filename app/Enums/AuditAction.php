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
    case InviteLinkCreated = 'member.invite_link_created';
    case InviteLinkRevoked = 'member.invite_link_revoked';
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

    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectArchived = 'project.archived';
    case ProjectUnarchived = 'project.unarchived';
    case ProjectMemberAdded = 'project.member_added';
    case ProjectMemberRemoved = 'project.member_removed';
    case SprintStarted = 'project.sprint_started';
    case SprintCompleted = 'project.sprint_completed';

    /*
     * Grouped under the `project` subject rather than a subject of their own:
     * somebody auditing how the project list changed is asking one question, and
     * splitting it across two filters would hide half the answer.
     */
    case ProjectGroupCreated = 'project.group_created';
    case ProjectGroupUpdated = 'project.group_updated';
    case ProjectGroupDeleted = 'project.group_deleted';

    case MessageDeletedByAdmin = 'message.deleted_by_admin';

    public function label(): string
    {
        return match ($this) {
            self::WorkspaceCreated => 'Workspace created',
            self::WorkspaceUpdated => 'Workspace updated',
            self::WorkspaceSettingsUpdated => 'Workspace settings updated',
            self::MemberInvited => 'Member invited',
            self::MemberInvitationRevoked => 'Invitation revoked',
            self::InviteLinkCreated => 'Invite link created',
            self::InviteLinkRevoked => 'Invite link revoked',
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
            self::ProjectCreated => 'Project created',
            self::ProjectUpdated => 'Project updated',
            self::ProjectArchived => 'Project archived',
            self::ProjectUnarchived => 'Project unarchived',
            self::ProjectMemberAdded => 'Added to project',
            self::ProjectMemberRemoved => 'Removed from project',
            self::SprintStarted => 'Sprint started',
            self::SprintCompleted => 'Sprint completed',
            self::ProjectGroupCreated => 'Project group created',
            self::ProjectGroupUpdated => 'Project group updated',
            self::ProjectGroupDeleted => 'Project group deleted',
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
