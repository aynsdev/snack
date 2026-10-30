<?php

namespace App\Services\Outbound;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\OutboundEventType;
use App\Models\Channel;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tells the agent dispatcher when a project's agent settings change, including
 * when they are switched off — the receiver keeps a copy and must hear about
 * every move.
 *
 * Callers lock and re-read the project under the workspace lock — see
 * {@see OutboundEvents} — and call {@see changing()} before they change it: the
 * "before" is described there, and the project as it ends up when the
 * transaction flushes.
 *
 * @phpstan-import-type AgentBlock from OutboundPayloads
 */
class AgentSettingsEvents
{
    public function __construct(
        private readonly OutboundEvents $events,
        private readonly OutboundPayloads $payloads,
        private readonly TaskEligibilityEvents $eligibility,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * Report the project's agent settings as the transaction leaves them,
     * against what they are now.
     *
     * @param  Project  $locked  Locked and re-read under the workspace lock; not yet changed.
     */
    public function changing(Project $locked): void
    {
        if (! $this->events->enabled()) {
            return;
        }

        $before = $this->payloads->agent(
            $locked->workspace_id,
            $this->originalId($locked, 'agent_channel_id'),
            $this->originalId($locked, 'agent_in_progress_column_id'),
            $this->originalId($locked, 'agent_review_column_id'),
        );

        $this->events->defer(OutboundEventType::ProjectAgentSettingsChanged, $locked->workspace_id, function () use ($locked, $before): array {
            $project = Project::query()->find($locked->id);

            return $project === null ? [] : [[
                'project' => $this->payloads->project($project),
                'before' => $before,
            ]];
        });
    }

    /**
     * Audit agent settings that changed without anybody using the settings
     * form — a channel turned into an announcement channel, a column or a
     * channel deleted — so the trail says why a project's agents stopped.
     *
     * @param  array<string, int|null>  $before  The project's {@see Project::AGENT_SETTINGS} before the change.
     */
    public function auditClearing(Project $project, array $before, ?User $actor): void
    {
        $after = $project->only(Project::AGENT_SETTINGS);

        if ($after === $before) {
            return;
        }

        $this->audit->handle(
            workspace: $project->workspace,
            action: AuditAction::ProjectAgentSettingsUpdated,
            actor: $actor,
            auditable: $project,
            oldValues: $before,
            newValues: $after,
        );
    }

    /**
     * Run a change to a channel, and report it to every project whose agents
     * talk there — a rename, an archive, a deletion, or a change of who can
     * see it all change what those projects' agents can do.
     *
     * The projects are selected only once the workspace is locked, so one
     * attached to the channel a moment earlier cannot be missed.
     *
     * @template T
     *
     * @param  Closure(array<int, int>): T  $mutation  Given the ids of the projects using the channel.
     * @param  bool  $visibility  Whether the change may alter who can see the channel.
     * @return T
     */
    public function aroundChannelChange(Channel $channel, Closure $mutation, bool $visibility = false, ?User $actor = null): mixed
    {
        return $this->events->transaction([$channel->workspace_id], function () use ($channel, $mutation, $visibility, $actor): mixed {
            $projects = Project::query()
                ->with('workspace')
                ->where('workspace_id', $channel->workspace_id)
                ->where('agent_channel_id', $channel->id)
                ->orderBy('id')
                ->when($this->events->enabled(), fn (Builder $query) => $query->lock('for no key update'))
                ->get();

            $before = [];

            foreach ($projects as $project) {
                $before[$project->id] = $project->only(Project::AGENT_SETTINGS);

                $this->changing($project);

                if ($visibility && $this->events->watching($project)) {
                    $this->eligibility->watchViewers($project);
                }
            }

            $result = $mutation($projects->modelKeys());

            if ($projects->isNotEmpty()) {
                Project::query()
                    ->with('workspace')
                    ->whereKey($projects->modelKeys())
                    ->get()
                    ->each(fn (Project $project) => $this->auditClearing($project, $before[$project->id], $actor));
            }

            return $result;
        });
    }

    private function originalId(Project $project, string $attribute): ?int
    {
        $value = $project->getOriginal($attribute);

        return $value === null ? null : (int) $value;
    }
}
