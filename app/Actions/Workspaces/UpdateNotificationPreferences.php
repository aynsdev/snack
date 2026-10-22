<?php

namespace App\Actions\Workspaces;

use App\Enums\NotificationLevel;
use App\Models\WorkspaceMember;

/**
 * Changes a member's workspace-wide notification defaults.
 *
 * These live in `workspace_members.notification_preferences`, a jsonb column, so
 * this action is the one place that decides what may be in it. Writing the
 * column from a controller would let each caller invent its own key names, and
 * a schemaless column with several writers accumulates keys nothing reads.
 *
 * The whole document is replaced rather than merged. Every field arrives from
 * the settings form on every save, so a merge would only serve to preserve keys
 * that are no longer part of the shape — which is exactly the drift the single
 * writer exists to prevent.
 */
class UpdateNotificationPreferences
{
    /**
     * @param  array{start: string, end: string}|null  $doNotDisturb
     */
    public function handle(
        WorkspaceMember $membership,
        NotificationLevel $defaultLevel,
        bool $emailEnabled,
        bool $digestEnabled,
        ?array $doNotDisturb,
        ?bool $pushEnabled = null,
    ): WorkspaceMember {
        /*
         * Null keeps what is stored. Push is set from the mobile app, and the
         * web settings form — which has no phone to speak for — leaves it out.
         */
        $pushEnabled ??= $membership->wantsPushNotifications();

        $membership->forceFill([
            'notification_preferences' => [
                /*
                 * The level a new channel membership starts at — see
                 * JoinChannel. Existing memberships keep whatever they were set
                 * to: someone who muted three channels last month did not ask
                 * for that to be undone by touching a default.
                 */
                'default_level' => $defaultLevel->value,
                'email' => ['enabled' => $emailEnabled],
                'push' => ['enabled' => $pushEnabled],
                'digest' => ['enabled' => $digestEnabled],
                // Omitted entirely rather than stored as null, so
                // WorkspaceMember::doNotDisturbSchedule() has one shape to read.
                ...($doNotDisturb === null ? [] : ['do_not_disturb' => $doNotDisturb]),
            ],
        ])->save();

        return $membership;
    }
}
