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

    /*
     * Its own subject rather than folded under `channel`.
     *
     * A standup is not a property of the channel it reports to: it DMs people on
     * a schedule, and "who set the app to message the team every morning, and
     * when did the questions change" is a question an administrator arrives with
     * whole. Filing it under the channel would answer it only for somebody who
     * already knew which channel to look in.
     */
    case StandupCreated = 'standup.created';
    case StandupUpdated = 'standup.updated';
    case StandupDeleted = 'standup.deleted';

    /*
     * Its own subject, for the reason standups have one. A webhook is a
     * standing permission for something outside the building to write into a
     * channel, and "what can post here without a login, who set that up, and
     * when was it turned off" is a question an administrator arrives with
     * whole.
     *
     * There is deliberately no `webhook.delivered` case. Deliveries are
     * messages, they land in a channel where everybody can read them, and
     * writing an audit row per delivery would bury every other entry in the log
     * under a CI job's build notifications.
     */
    case WebhookCreated = 'webhook.created';
    case WebhookUpdated = 'webhook.updated';
    case WebhookRevoked = 'webhook.revoked';

    /*
     * Its own case rather than a second `webhook.updated`, because it is the
     * entry somebody investigating actually needs: a hook that was off and is
     * now on again means a URL went dead and came back, and the log has to say
     * who did that and when. Folded into "updated" it would read as a rename.
     */
    case WebhookRestored = 'webhook.restored';

    /*
     * Signing, as three cases rather than one. Turning it on, turning it off,
     * and replacing the secret are three different sentences in an incident
     * review — "the hook stopped requiring proof" is the one that matters, and
     * a shared `webhook.signing_changed` would bury it next to a routine
     * rotation.
     */
    case WebhookSigningEnabled = 'webhook.signing_enabled';
    case WebhookSigningDisabled = 'webhook.signing_disabled';
    case WebhookSecretRotated = 'webhook.secret_rotated';

    case WebhookDeleted = 'webhook.deleted';

    /*
     * A member connecting an AI client to the workspace over MCP, or cutting
     * one off. Recorded because a connector reads what its owner can read and
     * posts under their name, and "which assistants can reach this workspace"
     * is a question an admin should be able to answer from the log.
     */
    case ConnectorCreated = 'connector.created';
    case ConnectorRevoked = 'connector.revoked';

    case MessageDeletedByAdmin = 'message.deleted_by_admin';

    /*
     * The asset library, under a subject of its own.
     *
     * Only the destructive half is recorded. Adding a file and renaming one are
     * visible in the library itself, with the curator's name against them, and a
     * row per upload would bury every other entry in the log under whichever
     * team last dropped in a folder of photographs. A deletion leaves nothing
     * behind to look at — the shelf is simply emptier than somebody remembers —
     * so it is the one that has to be written down.
     */
    case AssetDeleted = 'asset.deleted';
    case AssetFolderDeleted = 'asset.folder_deleted';

    /*
     * The assistant's configuration, under its own subject.
     *
     * Recorded in full — including the knowledge base — because these entries
     * answer a question nothing else can: "why did Bite tell the whole company
     * that?". The answer is almost always something an admin typed on that
     * screen, and without a trail the only record of it is the value that has
     * since been corrected.
     *
     * The knowledge entries are separated from the settings for the same reason
     * `workspace.settings_updated` is separate from `workspace.updated`:
     * changing what an assistant *knows* and changing where it may *speak* are
     * different kinds of event, and an administrator reading the log back
     * usually wants one of them and not the other.
     */
    case BiteSettingsUpdated = 'bite.settings_updated';
    case BiteMemoryCreated = 'bite.memory_created';
    case BiteMemoryUpdated = 'bite.memory_updated';
    case BiteMemoryDeleted = 'bite.memory_deleted';

    /*
     * HR, under one subject. Recorded are the changes to somebody's file and
     * the decisions made about them — approvals, corrections, paperwork — not
     * the everyday clocking in and asking for leave, which the HR screens
     * themselves already show with names and dates against them.
     */
    case EmployeeUpdated = 'hr.employee_updated';
    case EmploymentChanged = 'hr.employment_changed';
    case ContractCreated = 'hr.contract_created';
    case ContractUpdated = 'hr.contract_updated';
    case ContractDeleted = 'hr.contract_deleted';
    case EmployeeRecordCreated = 'hr.record_created';
    case EmployeeRecordDeleted = 'hr.record_deleted';
    case AbsenceRecorded = 'hr.absence_recorded';
    case AbsenceDeleted = 'hr.absence_deleted';
    case LeaveApproved = 'hr.leave_approved';
    case LeaveRejected = 'hr.leave_rejected';
    case TimeEntryUpdated = 'hr.time_entry_updated';
    case TimeEntryDeleted = 'hr.time_entry_deleted';
    case HrSettingsUpdated = 'hr.settings_updated';
    case ShiftCreated = 'hr.shift_created';
    case ShiftUpdated = 'hr.shift_updated';
    case ShiftDeleted = 'hr.shift_deleted';
    case ShiftRequestApproved = 'hr.shift_request_approved';
    case ShiftRequestRejected = 'hr.shift_request_rejected';
    case WorkTypeRequestApproved = 'hr.work_type_request_approved';
    case WorkTypeRequestRejected = 'hr.work_type_request_rejected';
    case AttendanceRequestApproved = 'hr.attendance_request_approved';
    case AttendanceRequestRejected = 'hr.attendance_request_rejected';
    case DocumentRequested = 'hr.document_requested';
    case DocumentRequestDeleted = 'hr.document_request_deleted';
    case DocumentApproved = 'hr.document_approved';
    case DocumentRejected = 'hr.document_rejected';
    case DisciplinaryActionIssued = 'hr.disciplinary_action_issued';
    case DisciplinaryActionDeleted = 'hr.disciplinary_action_deleted';
    case CompanyPolicyPublished = 'hr.policy_published';
    case CompanyPolicyUpdated = 'hr.policy_updated';
    case CompanyPolicyDeleted = 'hr.policy_deleted';
    case ChecklistStarted = 'hr.checklist_started';
    case ChecklistCompleted = 'hr.checklist_completed';
    case ChecklistCancelled = 'hr.checklist_cancelled';
    case ChecklistTemplateSaved = 'hr.checklist_template_saved';
    case ChecklistTemplateDeleted = 'hr.checklist_template_deleted';
    case ResignationAccepted = 'hr.resignation_accepted';
    case ResignationRejected = 'hr.resignation_rejected';

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
            self::StandupCreated => 'Standup created',
            self::StandupUpdated => 'Standup updated',
            self::StandupDeleted => 'Standup deleted',
            self::WebhookCreated => 'Webhook created',
            self::WebhookUpdated => 'Webhook updated',
            self::WebhookRevoked => 'Webhook turned off',
            self::WebhookRestored => 'Webhook turned back on',
            self::WebhookSigningEnabled => 'Webhook signing required',
            self::WebhookSigningDisabled => 'Webhook signing no longer required',
            self::WebhookSecretRotated => 'Webhook signing secret replaced',
            self::WebhookDeleted => 'Webhook deleted',
            self::ConnectorCreated => 'AI connector created',
            self::ConnectorRevoked => 'AI connector revoked',
            self::MessageDeletedByAdmin => 'Message removed by admin',
            self::BiteSettingsUpdated => 'Bite settings updated',
            self::BiteMemoryCreated => 'Bite knowledge added',
            self::BiteMemoryUpdated => 'Bite knowledge edited',
            self::BiteMemoryDeleted => 'Bite knowledge removed',
            self::AssetDeleted => 'Asset deleted',
            self::AssetFolderDeleted => 'Asset folder deleted',
            self::EmployeeUpdated => 'Employee file updated',
            self::EmploymentChanged => 'Employment history recorded',
            self::ContractCreated => 'Contract added',
            self::ContractUpdated => 'Contract updated',
            self::ContractDeleted => 'Contract deleted',
            self::EmployeeRecordCreated => 'Record filed',
            self::EmployeeRecordDeleted => 'Record deleted',
            self::AbsenceRecorded => 'Absence recorded',
            self::AbsenceDeleted => 'Absence removed',
            self::LeaveApproved => 'Leave approved',
            self::LeaveRejected => 'Leave rejected',
            self::TimeEntryUpdated => 'Time entry corrected',
            self::TimeEntryDeleted => 'Time entry deleted',
            self::HrSettingsUpdated => 'HR settings updated',
            self::ShiftCreated => 'Shift created',
            self::ShiftUpdated => 'Shift updated',
            self::ShiftDeleted => 'Shift deleted',
            self::ShiftRequestApproved => 'Shift change approved',
            self::ShiftRequestRejected => 'Shift change rejected',
            self::WorkTypeRequestApproved => 'Work type change approved',
            self::WorkTypeRequestRejected => 'Work type change rejected',
            self::AttendanceRequestApproved => 'Attendance correction approved',
            self::AttendanceRequestRejected => 'Attendance correction rejected',
            self::DocumentRequested => 'Documents requested',
            self::DocumentRequestDeleted => 'Document request deleted',
            self::DocumentApproved => 'Document approved',
            self::DocumentRejected => 'Document rejected',
            self::DisciplinaryActionIssued => 'Disciplinary action issued',
            self::DisciplinaryActionDeleted => 'Disciplinary action deleted',
            self::CompanyPolicyPublished => 'Policy published',
            self::CompanyPolicyUpdated => 'Policy updated',
            self::CompanyPolicyDeleted => 'Policy deleted',
            self::ChecklistStarted => 'Checklist started',
            self::ChecklistCompleted => 'Checklist completed',
            self::ChecklistCancelled => 'Checklist cancelled',
            self::ChecklistTemplateSaved => 'Checklist template saved',
            self::ChecklistTemplateDeleted => 'Checklist template deleted',
            self::ResignationAccepted => 'Resignation accepted',
            self::ResignationRejected => 'Resignation rejected',
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
