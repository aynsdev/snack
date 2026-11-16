<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use App\Services\Outbound\AgentSettingsEvents;
use App\Services\Outbound\OutboundEvents;
use App\Services\Outbound\TaskEligibilityEvents;
use App\Services\SlugGenerator;
use App\Support\GitHubRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Renames a project and edits its settings.
 *
 * # What a rename does and does not change
 *
 * The slug is regenerated, because it is what the URL carries and a project
 * called something else should not still be addressed by its old name.
 *
 * The key is not — not automatically, and not without the caller asking. It is
 * baked into every reference anyone has ever quoted: PWA-1499 written in a
 * channel last month, in a commit message, in somebody's notes. Changing it
 * silently would invalidate all of them at once. A caller that does pass a key
 * has said so deliberately, and the unique constraint still has the last word.
 *
 * # Agent settings
 *
 * Saved through here like everything else, but audited as their own entry and
 * reported to the agent dispatcher — in the same transaction, so the receiver's
 * copy of a project's settings can never disagree with what committed.
 */
class UpdateProject
{
    public const AGENT_CHANNEL_REFUSED = 'Choose a channel in this workspace that you can see. Announcement channels cannot be used.';

    public const AGENT_COLUMNS_SAME = 'The in-progress and review columns must be different columns.';

    public function __construct(
        private readonly SlugGenerator $slugs,
        private readonly RecordAuditLog $audit,
        private readonly AgentSettingsEvents $agentSettings,
        private readonly TaskEligibilityEvents $eligibility,
        private readonly OutboundEvents $events,
    ) {}

    /**
     * @param  array{
     *     name?: string,
     *     key?: string,
     *     description?: string|null,
     *     repository_url?: string|null,
     *     repository_base_branch?: string|null,
     *     color?: string,
     *     uses_sprints?: bool,
     *     card_fields?: array<string, bool>,
     *     project_group_id?: int|null,
     *     next_task_number?: int,
     *     agent_channel_id?: int|null,
     *     agent_in_progress_column_id?: int|null,
     *     agent_review_column_id?: int|null,
     * }  $attributes
     */
    public function handle(Project $project, User $actor, array $attributes): Project
    {
        if (array_key_exists('key', $attributes)) {
            $attributes['key'] = strtoupper($attributes['key']);
        }

        if (array_key_exists('repository_url', $attributes)) {
            $attributes['repository_url'] = is_string($attributes['repository_url'])
                ? GitHubRepository::normalize($attributes['repository_url'])
                : null;

            if ($attributes['repository_url'] === null) {
                $attributes['repository_base_branch'] = null;
            }
        }

        if (array_key_exists('card_fields', $attributes)) {
            // Stored as the project's overrides rather than the resolved set, so
            // a toggle added in a later release still picks up its default here
            // instead of being frozen false — see Project::cardFields().
            $attributes['card_fields'] = array_intersect_key(
                array_map(static fn (mixed $value): bool => (bool) $value, $attributes['card_fields']),
                Project::CARD_FIELD_DEFAULTS,
            );
        }

        /*
         * Read again under a row lock, and every "before" taken from that read.
         * Two people saving at once would otherwise each describe the settings
         * from the page they loaded, and the agent dispatcher would be told a
         * history that never happened — or have agents switched back on by the
         * slower of the two.
         */
        $locked = $this->events->transaction([$project->workspace_id], function () use ($project, $actor, $attributes): Project {
            $locked = Project::query()->lock('for no key update')->findOrFail($project->id);
            $locked->setRelation('workspace', $project->workspace);

            if (array_intersect(Project::AGENT_SETTINGS, array_keys($attributes)) !== []) {
                $this->revalidateAgentSettings($locked, $actor, $attributes);
            }

            $before = $locked->only([
                'name', 'key', 'description', 'repository_url', 'repository_base_branch', 'color', 'uses_sprints', 'card_fields',
                'project_group_id', 'next_task_number',
            ]);

            if (array_key_exists('name', $attributes) && $attributes['name'] !== $locked->name) {
                $attributes['slug'] = $this->slugs->forProject(
                    $locked->workspace,
                    $attributes['name'],
                    ignoreId: $locked->id,
                );
            }

            /*
             * Settings first, then the tasks they affect — the order every
             * agent-channel change reports in; see OutboundEvents.
             *
             * A new agent channel changes, for every assignee, whether they can
             * see where their agent works — so their tasks are watched against
             * the old channel before it goes. Only for a switch: switching
             * agents on or off is the settings event's to report.
             */
            $changing = array_filter(
                Project::AGENT_SETTINGS,
                fn (string $setting): bool => array_key_exists($setting, $attributes) && self::id($attributes[$setting]) !== $locked->{$setting},
            );

            if ($changing !== []) {
                $this->agentSettings->changing($locked);
            }

            if (in_array('agent_channel_id', $changing, true) && $this->events->watching($locked)) {
                $this->eligibility->watchViewers($locked);
            }

            $locked->fill($attributes)->unsetRelation('agentChannel');

            if (! $locked->isDirty()) {
                return $locked;
            }

            $agentSettingsChanged = $locked->isDirty(Project::AGENT_SETTINGS);

            $agentOriginal = array_map(
                fn (string $attribute): mixed => $locked->getOriginal($attribute),
                array_combine(Project::AGENT_SETTINGS, Project::AGENT_SETTINGS),
            );
            $detailsChanged = $locked->isDirty(array_keys($before));

            $locked->save();

            if ($detailsChanged) {
                $this->audit->handle(
                    workspace: $locked->workspace,
                    action: AuditAction::ProjectUpdated,
                    actor: $actor,
                    auditable: $locked,
                    oldValues: $before,
                    newValues: $locked->only(array_keys($before)),
                );
            }

            if ($agentSettingsChanged) {
                $this->audit->handle(
                    workspace: $locked->workspace,
                    action: AuditAction::ProjectAgentSettingsUpdated,
                    actor: $actor,
                    auditable: $locked,
                    oldValues: $agentOriginal,
                    newValues: $locked->only(Project::AGENT_SETTINGS),
                );
            }

            return $locked;
        });

        // The caller's instance is brought up to date rather than replaced, so
        // anything it already holds — loaded relations, a route binding — stays.
        $project->setRawAttributes($locked->getAttributes(), sync: true);

        return $project;
    }

    /**
     * The request's agent-setting checks, made again under the lock: the
     * request validated against the project as it loaded it, and a concurrent
     * save may have changed the channel or the columns since.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function revalidateAgentSettings(Project $locked, User $actor, array $attributes): void
    {
        /*
         * The person as they stand now, not as the request found them: a
         * membership suspended or a role changed while it waited for the lock
         * must not still be choosing where agents talk. Read fresh, without
         * whatever memberships the request's copy had loaded.
         */
        $actor = User::query()->findOrFail($actor->id);

        Gate::forUser($actor)->authorize('configureAgents', $locked);

        $channelId = self::id($attributes['agent_channel_id'] ?? null);

        // Keeping the channel already set is always allowed, as in the request.
        if ($channelId !== null && $channelId !== $locked->agent_channel_id && ! $locked->acceptsAgentChannelFrom($actor, $channelId)) {
            throw ValidationException::withMessages(['agent_channel_id' => self::AGENT_CHANNEL_REFUSED]);
        }

        $inProgress = array_key_exists('agent_in_progress_column_id', $attributes)
            ? self::id($attributes['agent_in_progress_column_id'])
            : $locked->agent_in_progress_column_id;
        $review = array_key_exists('agent_review_column_id', $attributes)
            ? self::id($attributes['agent_review_column_id'])
            : $locked->agent_review_column_id;

        if ($inProgress !== null && $inProgress === $review) {
            $field = array_key_exists('agent_review_column_id', $attributes) ? 'agent_review_column_id' : 'agent_in_progress_column_id';

            throw ValidationException::withMessages([$field => self::AGENT_COLUMNS_SAME]);
        }
    }

    /**
     * A setting's id as the database will hold it, however the caller typed it.
     */
    private static function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
