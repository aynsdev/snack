<?php

namespace App\Enums;

/**
 * Every event Snack sends to the agent dispatcher. The value is the envelope's
 * `type` and is part of the integration contract — never rename one.
 */
enum OutboundEventType: string
{
    case TaskEligibilityChanged = 'task.eligibility_changed';
    case TaskContentChanged = 'task.content_changed';
    case AgentConnectorRevoked = 'agent.connector_revoked';
    case WorkspaceMemberRemoved = 'workspace.member_removed';
    case MessageCreated = 'message.created';
    case MessageUpdated = 'message.updated';
    case MessageDeleted = 'message.deleted';
    case ProjectAgentSettingsChanged = 'project.agent_settings_changed';
    case AgentSummoned = 'agent.summoned';
}
