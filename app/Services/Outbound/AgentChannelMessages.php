<?php

namespace App\Services\Outbound;

use App\Enums\OutboundEventStatus;
use App\Enums\OutboundEventType;
use App\Models\AgentSummon;
use App\Models\Channel;
use App\Models\Message;
use App\Models\OutboundEvent;
use App\Models\Project;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells the agent dispatcher about messages in project agent channels — and
 * only those.
 *
 * Which conversations leave Snack is decided by a project member choosing an
 * agent channel in the project's settings — or, for one thread, by somebody
 * writing `@agent` in it; see {@see AgentSummons}. A thread an agent started
 * for one of a project's tickets keeps being reported after the project moves
 * its agent channel, so work under way finishes where it began; see
 * {@see ticketThreadProjects()}. A message
 * anywhere that is not the agent channel of some live project in its own
 * workspace is never exported, whoever sent it and however it was sent — and
 * even there, only thread replies and the agents' own posts, and their edits
 * and deletions.
 *
 * Writers run through {@see transaction()}, which locks the workspace before
 * they write anything; the projects and the channel are read again when the
 * transaction flushes, so an event can never report a channel's agents as on
 * after the event that switched them off.
 */
class AgentChannelMessages
{
    public function __construct(
        private readonly OutboundEvents $events,
        private readonly OutboundPayloads $payloads,
    ) {}

    /**
     * Run a write to a message — under the workspace lock when an unlocked
     * read says it is in some project's agent channel and is one that is
     * reported, in a plain transaction otherwise. See the accepted gap on
     * {@see OutboundEvents}.
     *
     * @template T
     *
     * @param  bool  $reportable  See {@see reportable()}; for a message not yet sent, whether it is a reply or an agent's post.
     * @param  Closure(): T  $callback
     * @param  int|null  $threadRootId  The thread the message is in, which an open summons exports.
     * @param  bool  $summoning  Whether the message may summon an agent, which is reported wherever it is.
     * @return T
     */
    public function transaction(int $workspaceId, ?int $channelId, bool $reportable, Closure $callback, ?int $threadRootId = null, bool $summoning = false): mixed
    {
        $locks = $channelId !== null && $this->events->enabled() && ($summoning || ($reportable && (
            $this->listeningProjects($workspaceId, $channelId)->exists()
            || ($threadRootId !== null && $this->summoned($threadRootId))
            || ($threadRootId !== null && $this->ticketThreadProjects($workspaceId, $channelId, $threadRootId)->isNotEmpty())
        )));

        if (! $locks) {
            return DB::transaction(fn (): mixed => $callback());
        }

        return $this->events->transaction([$workspaceId], $callback);
    }

    /**
     * Whether changes to this message are exported: every reply, because a
     * thread is where an agent and its people talk — but of the roots only an
     * agent's own posts, its plans, whose edits and deletions the dispatcher
     * has to act on. Everything else said in the channel stays in Snack.
     */
    public function reportable(Message $message): bool
    {
        return $message->parent_message_id !== null
            || is_array($message->metadata['agent'] ?? null)
            || ($message->channel_id !== null && $this->events->enabled() && $this->summoned($message->id));
    }

    /**
     * Whether an open `@agent` summons exports this thread.
     */
    public function summoned(int $threadRootId): bool
    {
        return AgentSummon::query()->where('thread_root_id', $threadRootId)->open()->exists();
    }

    /**
     * Replies, and an agent's own roots — the thread it starts, which the
     * dispatcher checks the agent's word about against. A person's root post
     * stays in Snack.
     */
    public function created(Message $message): void
    {
        $this->record(OutboundEventType::MessageCreated, $message, fn (): ?array => $this->live($message));
    }

    public function updated(Message $message): void
    {
        $this->record(OutboundEventType::MessageUpdated, $message, fn (): ?array => $this->live($message));
    }

    /**
     * The message as the transaction leaves it — or nothing, when the same
     * transaction went on to delete it: its `message.deleted` is all the
     * receiver hears, and the text it no longer has never leaves.
     *
     * @return array{message: array<string, mixed>}|null
     */
    private function live(Message $message): ?array
    {
        $current = Message::query()->with(['sender', 'attachments'])->find($message->id);

        return $current === null ? null : ['message' => $this->payloads->message($current)];
    }

    /**
     * Events about this message that have not been attempted yet, or are done
     * with, lose its text first: the person deleted what they said, and the
     * outbox must not deliver it a moment later regardless, nor keep it until
     * it is pruned.
     */
    public function deleted(Message $message): void
    {
        if ($message->channel_id !== null && $this->events->enabled()) {
            $this->scrub($message);
        }

        $this->record(OutboundEventType::MessageDeleted, $message, fn (): array => [
            'message_id' => $message->id,
            'thread_root_id' => $message->threadRootId(),
        ]);

        // Deleting the thread ends what was summoned into it.
        if ($message->parent_message_id === null) {
            AgentSummon::query()->where('thread_root_id', $message->id)->open()->update(['closed_at' => now()]);
        }
    }

    /**
     * @param  Closure(): (array<string, mixed>|null)  $body  Null to report nothing after all.
     */
    private function record(OutboundEventType $type, Message $message, Closure $body): void
    {
        if ($message->channel_id === null || ! $this->events->locked($message->workspace_id) || ! $this->reportable($message)) {
            return;
        }

        /*
         * Taken now, under the lock, rather than at flush: deleting a thread's
         * root closes its summons in the same transaction, and that deletion is
         * still the receiver's to hear. The summoning message itself travels as
         * `agent.summoned`, so it is not reported again on its own.
         */
        $summons = AgentSummon::query()->where('thread_root_id', $message->threadRootId())->open()->first();
        $exportsThread = $summons !== null && ! ($type === OutboundEventType::MessageCreated && $summons->message_id === $message->id);
        // A new root starts new work, which belongs in the current agent channel only.
        $continuesTicketThread = ! ($type === OutboundEventType::MessageCreated && $message->parent_message_id === null);

        $this->events->defer($type, $message->workspace_id, function () use ($message, $body, $exportsThread, $continuesTicketThread): array {
            $channel = Channel::query()
                ->where('workspace_id', $message->workspace_id)
                ->find($message->channel_id);

            // An archived channel switches its projects' agents off.
            if ($channel === null || $channel->is_archived) {
                return [];
            }

            $projects = $this->listeningProjects($message->workspace_id, $channel->id)->get();

            if ($continuesTicketThread) {
                $projects = $projects
                    ->merge($this->ticketThreadProjects($message->workspace_id, $channel->id, $message->threadRootId()))
                    ->unique('id')
                    ->sortBy('id')
                    ->values();
            }

            $data = $body();

            if ($data === null || ($projects->isEmpty() && ! $exportsThread)) {
                return [];
            }

            return [[
                'projects' => $this->payloads->projects($projects, $channel),
                'channel' => $this->payloads->channel($channel),
                ...$data,
            ]];
        });
    }

    /**
     * Rows already attempted and still pending are left alone: a retry must
     * send exactly what the first attempt sent, and that attempt may already
     * have reached the receiver. A row being claimed right now has its
     * `attempts` raised before the claim commits, so it is skipped too.
     */
    private function scrub(Message $message): void
    {
        OutboundEvent::query()
            ->where('workspace_id', $message->workspace_id)
            ->whereIn('type', [OutboundEventType::MessageCreated, OutboundEventType::MessageUpdated])
            ->where('payload->data->message->id', (string) $message->id)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('status', OutboundEventStatus::Pending)->where('attempts', 0))
                ->orWhereIn('status', [OutboundEventStatus::Delivered, OutboundEventStatus::Dead]))
            ->update(['payload' => DB::raw(<<<'SQL'
                jsonb_set(jsonb_set(payload, '{data,message,body}', '""'), '{data,message,body_plaintext}', '""')
            SQL)]);
    }

    /**
     * The project whose ticket an agent started this thread for, when the
     * thread is not in that project's agent channel any more: the project moved
     * its agent channel while the work was under way. Only an agent's own root
     * that names a ticket counts, and only while the project still has agents
     * on (an agent channel, not archived). Matched by workspace as well, so no
     * bad row can carry a thread across tenants.
     *
     * @return Collection<int, Project>
     */
    private function ticketThreadProjects(int $workspaceId, int $channelId, int $threadRootId): Collection
    {
        // With trashed: the root's own deletion is built after it is soft-deleted, and the dispatcher must still hear it.
        $root = Message::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('channel_id', $channelId)
            ->whereNull('parent_message_id')
            ->find($threadRootId, ['id', 'metadata']);
        $agent = $root?->metadata['agent'] ?? null;
        $taskId = is_array($agent) && isset($agent['connector_id']) && is_int($agent['task_id'] ?? null) ? $agent['task_id'] : null;

        if ($taskId === null) {
            return new Collection;
        }

        return Project::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('agent_channel_id')
            ->where('agent_channel_id', '!=', $channelId)
            ->notArchived()
            ->whereHas('tasks', fn (Builder $query) => $query->whereKey($taskId))
            ->get();
    }

    /**
     * Live projects in the workspace that use the channel as their agent
     * channel. The workspace is matched as well as the channel so that no bad
     * row anywhere can carry a message across tenants.
     *
     * @return Builder<Project>
     */
    private function listeningProjects(int $workspaceId, int $channelId): Builder
    {
        return Project::query()
            ->where('workspace_id', $workspaceId)
            ->where('agent_channel_id', $channelId)
            ->notArchived()
            ->orderBy('id');
    }
}
