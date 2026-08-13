<?php

namespace App\Actions\Bite;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\BiteCapability;
use App\Models\BiteSetting;
use App\Models\User;
use App\Models\Workspace;

/**
 * Saves a workspace's assistant configuration.
 *
 * Authorization is the caller's, as everywhere: this takes resolved models and
 * a validated array, never a request.
 *
 * # The row is created here and nowhere else
 *
 * {@see BiteSetting::forWorkspace()} deliberately does not persist, because it
 * is called on the path that answers a message — on a queue worker, inside
 * somebody else's transaction. Saving is the one moment a row should come into
 * being, which is why this is the only place that writes one.
 *
 * # What the audit entry records
 *
 * The values that changed, old and new, including the instructions in full. An
 * entry that said only "settings updated" would be useless for the question
 * this trail exists to answer — why the assistant started behaving differently
 * last Tuesday — because the answer is almost always the text somebody pasted
 * in and has since corrected.
 */
class UpdateBiteSettings
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  Already validated, and already
     *                                            narrowed to this workspace — see UpdateBiteSettingsRequest, which
     *                                            resolves the channel ids against the workspace's own channels.
     */
    public function handle(Workspace $workspace, User $actor, array $attributes): BiteSetting
    {
        $settings = BiteSetting::forWorkspace($workspace);

        $before = $this->snapshot($settings);

        $settings->fill([
            ...$attributes,
            'workspace_id' => $workspace->id,
            'updated_by' => $actor->id,
        ]);

        $after = $this->snapshot($settings);

        /*
         * Compared as snapshots rather than with `isDirty`, and that is the
         * whole point of the method below.
         *
         * `capabilities` is stored as null to mean "all of them", and the form
         * always posts the resolved list — so an admin who opened the screen and
         * pressed Save without touching anything would look dirty at the column
         * level while having changed nothing at all. An audit trail whose
         * entries are mostly identical old and new values is one nobody reads.
         */
        if ($before === $after) {
            return $settings;
        }

        $settings->save();

        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::BiteSettingsUpdated,
            actor: $actor,
            auditable: $settings,
            oldValues: $before,
            newValues: $after,
        );

        return $settings;
    }

    /**
     * The settings as a reader of the log should see them — and as two versions
     * of them should be compared.
     *
     * Normalized on both counts, which is the same job:
     *
     *  - enums are unwrapped to their stored strings, so the trail says
     *    `admins` rather than a serialized object;
     *  - the capability list is *resolved*, so the stored null that means "all
     *    of them" compares equal to the full list the form posts back;
     *  - both lists are sorted, because the guard does not care what order its
     *    channels are in and neither should a comparison of two saves.
     *
     * Channel ids are left as ids rather than names: the log is read beside the
     * workspace it belongs to, where an id can be looked up, and names would go
     * stale the moment somebody renamed a channel.
     *
     * @return array<string, mixed>
     */
    private function snapshot(BiteSetting $settings): array
    {
        $channelIds = $settings->guardedChannelIds();
        sort($channelIds);

        $capabilities = array_map(
            fn (BiteCapability $capability): string => $capability->value,
            $settings->enabledCapabilities(),
        );
        sort($capabilities);

        return [
            'is_enabled' => $settings->is_enabled,
            'audience' => $settings->audience->value,
            'channel_mode' => $settings->channel_mode->value,
            'channel_ids' => $channelIds,
            'instructions' => $settings->instructions,
            'context_messages' => $settings->context_messages,
            'max_steps' => $settings->max_steps,
            'capabilities' => $capabilities,
        ];
    }
}
