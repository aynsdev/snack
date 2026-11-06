<?php

namespace App\Services\Outbound;

use App\Enums\ProjectRole;
use App\Models\BoardColumn;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Support\OneLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The shared blocks outbound events are built from.
 *
 * Their shapes are the integration contract with the agent dispatcher, so every
 * block is spelled out key by key rather than derived from a model's
 * `toArray()` — a column added to a table must not quietly become a field the
 * other side starts depending on.
 *
 * @phpstan-type AgentBlock array{channel_id: int|null, channel_name: string|null, in_progress_column_id: int|null, in_progress_column_name: string|null, review_column_id: int|null, review_column_name: string|null}
 * @phpstan-type ProjectBlock array{id: int, key: string, name: string, lead_user_ids: array<int, int>, archived: bool, agent: AgentBlock}
 * @phpstan-type EligibilitySnapshot array{assignee_id: int|null, label_ids: array<int, int>, label_names: array<int, string>, assignee_can_view_agent_channel: bool}
 */
class OutboundPayloads
{
    /**
     * A project with its current agent settings, so the receiver never needs a
     * copy of its own.
     *
     * @return ProjectBlock
     */
    public function project(Project $project, ?Channel $agentChannel = null): array
    {
        return $this->projects(new Collection([$project]), $agentChannel)[0];
    }

    /**
     * Several project blocks for the cost of one: leads, channels and columns
     * are each read in a single query however many projects there are, because
     * this runs inside the transaction that sends a message.
     *
     * @param  Collection<int, Project>  $projects
     * @param  Channel|null  $agentChannel  A channel already in hand, to reuse rather than re-read.
     * @return array<int, ProjectBlock>
     */
    public function projects(Collection $projects, ?Channel $agentChannel = null): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $leads = ProjectMember::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->where('role', ProjectRole::Lead)
            ->orderBy('user_id')
            ->get(['project_id', 'user_id'])
            ->groupBy('project_id');

        $channels = $this->channels($projects->pluck('agent_channel_id')->all(), $agentChannel);
        $columns = $this->columnNames($projects->flatMap(fn (Project $project): array => [
            $project->agent_in_progress_column_id,
            $project->agent_review_column_id,
        ])->all());

        return $projects->values()->map(fn (Project $project): array => [
            'id' => $project->id,
            'key' => $project->key,
            'name' => $project->name,
            'lead_user_ids' => $leads->get($project->id, new Collection)
                ->map(fn (ProjectMember $member): int => (int) $member->user_id)
                ->values()
                ->all(),
            'archived' => $project->is_archived,
            'agent' => $this->agentBlock(
                $this->channelOf($channels, $project->workspace_id, $project->agent_channel_id),
                $project->agent_in_progress_column_id,
                $project->agent_review_column_id,
                $columns,
            ),
        ])->all();
    }

    /**
     * Taken as ids rather than a project so the settings *before* a change can
     * be described from the model's original attributes.
     *
     * @return AgentBlock
     */
    public function agent(int $workspaceId, ?int $channelId, ?int $inProgressColumnId, ?int $reviewColumnId): array
    {
        return $this->agentBlock(
            $this->channelOf($this->channels([$channelId]), $workspaceId, $channelId),
            $inProgressColumnId,
            $reviewColumnId,
            $this->columnNames([$inProgressColumnId, $reviewColumnId]),
        );
    }

    /**
     * An archived agent channel is reported as no channel: nothing can be
     * posted there, so as far as the receiver is concerned agents are off.
     *
     * @param  SupportCollection<int, string>  $columns
     * @return AgentBlock
     */
    private function agentBlock(?Channel $channel, ?int $inProgressColumnId, ?int $reviewColumnId, SupportCollection $columns): array
    {
        $usable = $channel !== null && ! $channel->is_archived;

        return [
            'channel_id' => $usable ? $channel->id : null,
            'channel_name' => $usable ? OneLine::clean($channel->name) : null,
            'in_progress_column_id' => $inProgressColumnId,
            'in_progress_column_name' => $inProgressColumnId === null ? null : $columns->get($inProgressColumnId),
            'review_column_id' => $reviewColumnId,
            'review_column_name' => $reviewColumnId === null ? null : $columns->get($reviewColumnId),
        ];
    }

    /**
     * The project's agent channel, only if it is in the project's own
     * workspace — a bad id must never put another tenant's channel in a
     * payload.
     *
     * @param  Collection<int, Channel>  $channels
     */
    private function channelOf(Collection $channels, int $workspaceId, ?int $channelId): ?Channel
    {
        $channel = $channelId === null ? null : $channels->get($channelId);

        return $channel?->workspace_id === $workspaceId ? $channel : null;
    }

    /**
     * @param  array<int, int|null>  $ids
     * @return Collection<int, Channel>
     */
    private function channels(array $ids, ?Channel $known = null): Collection
    {
        $ids = array_values(array_unique(array_filter($ids, fn (?int $id): bool => $id !== null)));

        if ($ids === []) {
            return new Collection;
        }

        if ($known !== null && $ids === [$known->id]) {
            return new Collection([$known->id => $known]);
        }

        return Channel::query()->whereKey($ids)->get(['id', 'workspace_id', 'name', 'is_archived'])->keyBy('id');
    }

    /**
     * @param  array<int, int|null>  $ids
     * @return SupportCollection<int, string>
     */
    private function columnNames(array $ids): SupportCollection
    {
        $ids = array_values(array_unique(array_filter($ids, fn (?int $id): bool => $id !== null)));

        return $ids === []
            ? new SupportCollection
            : BoardColumn::query()->whereKey($ids)->pluck('name', 'id');
    }

    /**
     * A task as it stands. Expects `column` and `labels` loaded, and the
     * project's `workspace` — a batch of these must not query per task.
     *
     * @return array<string, mixed>
     */
    public function task(Project $project, Task $task): array
    {
        $reference = $project->reference($task->number);
        $labels = $this->labels($task);

        return [
            'id' => $task->id,
            'reference' => $reference,
            'title' => $task->title,
            'description' => $task->description,
            'type' => $task->type->value,
            'priority' => $task->priority->value,
            'column_id' => $task->board_column_id,
            'column_name' => $task->column->name,
            'assignee_id' => $task->assignee_id,
            'reporter_id' => $task->reporter_id,
            ...$labels,
            'url' => $this->taskUrl($project, $reference),
        ];
    }

    /**
     * The part of a task that decides whether an agent should be working it.
     *
     * Labels in id order, names alongside, so two snapshots of an unchanged
     * task compare equal with a plain `===`. Whether the assignee can see the
     * agent channel is worked out by the caller, which knows the channel.
     *
     * @return EligibilitySnapshot
     */
    public function eligibility(Task $task, bool $assigneeCanViewAgentChannel): array
    {
        return [
            'assignee_id' => $task->assignee_id === null ? null : (int) $task->assignee_id,
            ...$this->labels($task),
            'assignee_can_view_agent_channel' => $assigneeCanViewAgentChannel,
        ];
    }

    /**
     * @return array{label_ids: array<int, int>, label_names: array<int, string>}
     */
    private function labels(Task $task): array
    {
        $labels = $task->labels->sortBy('id')->values();

        return [
            'label_ids' => $labels->map(fn (TaskLabel $label): int => $label->id)->all(),
            'label_names' => $labels->map(fn (TaskLabel $label): string => $label->name)->all(),
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    public function channel(Channel $channel): array
    {
        return ['id' => $channel->id, 'name' => OneLine::clean($channel->name)];
    }

    /**
     * A message as it stands. Load `sender` and `attachments` with it — a batch
     * of these must not query per message.
     *
     * `attachments` lists the files by the id Snack's `read_file` tool takes,
     * so the receiver can open one without searching for it first; never the
     * storage key or a download link, which are Snack's to hand out.
     *
     * @return array<string, mixed>
     */
    public function message(Message $message): array
    {
        $agent = $message->metadata['agent'] ?? null;

        return [
            'id' => $message->id,
            'parent_message_id' => $message->parent_message_id,
            'thread_root_id' => $message->threadRootId(),
            'sender_id' => $message->sender_id,
            'sender_is_bot' => $message->sender?->isBot() ?? false,
            'type' => $message->type->value,
            'body' => $message->body,
            'body_plaintext' => $message->body_plaintext,
            'agent' => is_array($agent) ? $agent : null,
            'attachments' => $message->attachments
                ->sortBy('id')
                ->values()
                ->map(fn (MessageAttachment $file): array => [
                    'id' => $file->id,
                    'name' => OneLine::clean($file->original_name),
                    'mime_type' => $file->mime_type,
                    'size' => $file->size,
                ])
                ->all(),
            'created_at' => OutboundEvents::timestamp($message->created_at),
            'edited_at' => OutboundEvents::timestamp($message->edited_at),
        ];
    }

    /**
     * Rooted at the configured URL rather than the current request's host, so a
     * link built inside a queued job and one built during a request agree.
     */
    public function taskUrl(Project $project, string $reference): string
    {
        return rtrim((string) config('app.url'), '/').route('projects.board', [
            'workspace' => $project->workspace->slug,
            'project' => $project->slug,
            'task' => $reference,
        ], absolute: false);
    }
}
