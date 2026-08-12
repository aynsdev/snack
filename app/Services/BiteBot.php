<?php

namespace App\Services;

use App\Models\Workspace;

/**
 * The account the workspace assistant speaks as.
 *
 * Everything about how a bot account is created, seated in its workspace, and
 * posts is {@see BotAccount}. What is different about this one is that people
 * address it: Standup and the webhooks talk, Bite is talked to.
 *
 * That is why it asks for the bare handle first. It is also why being refused
 * the bare handle does not matter — {@see BiteSummons} decides what counts as
 * addressing Bite, and it does not read `users.username`.
 */
class BiteBot extends BotAccount
{
    protected function displayName(): string
    {
        return 'Bite';
    }

    protected function title(): string
    {
        return 'Workspace assistant';
    }

    protected function emailFor(Workspace $workspace): string
    {
        return "bite-bot+{$workspace->id}@snack.invalid";
    }

    /**
     * The face, shipped with the application.
     *
     * Bite is talked *to*, so unlike Standup it appears in the DM list, the
     * mention autocomplete, and the member roster — places where a monogram
     * reads as an account somebody forgot to finish setting up rather than as
     * a piece of software.
     */
    protected function avatarSource(): ?string
    {
        return resource_path('images/bite-avatar.png');
    }

    /**
     * The bare handle where it is free, a qualified one where it is not.
     *
     * `users.username` is unique across the installation while these accounts
     * are per workspace, so on a multi-workspace instance exactly one of them
     * can be `@bite` and the rest are `@bite-{id}`. Which one wins is whichever
     * workspace summoned it first, and nothing depends on the answer: the
     * handle's only job here is to make the mention autocomplete show something
     * sensible.
     *
     * @return non-empty-list<string>
     */
    protected function usernameCandidates(Workspace $workspace): array
    {
        return [
            (string) config('snack.bite.handle', 'bite'),
            config('snack.bite.handle', 'bite')."-{$workspace->id}",
        ];
    }
}
