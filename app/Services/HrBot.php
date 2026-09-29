<?php

namespace App\Services;

use App\Models\Workspace;

/**
 * The account HR notices arrive from — leave requests and their outcomes.
 *
 * Everything about how a bot account is created, seated in its workspace, and
 * posts is {@see BotAccount}; what is left here is what makes this one HR.
 *
 * Announcements do not come from here: they are posted as the admin who wrote
 * them, because `@everyone` is an admin's right and a bot is deliberately a
 * plain member — see {@see App\Actions\Hr\PublishAnnouncement}.
 */
class HrBot extends BotAccount
{
    protected function displayName(): string
    {
        return 'HR';
    }

    protected function title(): string
    {
        return 'HR app';
    }

    protected function emailFor(Workspace $workspace): string
    {
        return "hr-bot+{$workspace->id}@snack.invalid";
    }

    /**
     * @return non-empty-list<string>
     */
    protected function usernameCandidates(Workspace $workspace): array
    {
        return ["hr-{$workspace->id}"];
    }
}
