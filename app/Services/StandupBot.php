<?php

namespace App\Services;

use App\Models\Workspace;

/**
 * The account the standup app speaks as.
 *
 * Everything about how a bot account is created, seated in its workspace, and
 * posts is {@see BotAccount}; what is left here is the four things that make
 * this one Standup rather than something else.
 */
class StandupBot extends BotAccount
{
    protected function displayName(): string
    {
        return 'Standup';
    }

    protected function title(): string
    {
        return 'Standup app';
    }

    protected function emailFor(Workspace $workspace): string
    {
        return "standup-bot+{$workspace->id}@snack.invalid";
    }

    /**
     * A handle that reads as one and cannot collide with a person's.
     *
     * `standup` alone would be claimable by whoever registered first; suffixing
     * the workspace id keeps every installation's bots distinct without a
     * uniqueness loop. Only one candidate because nobody types this — the
     * standup bot is never addressed by name.
     *
     * @return non-empty-list<string>
     */
    protected function usernameCandidates(Workspace $workspace): array
    {
        return ["standup-{$workspace->id}"];
    }
}
