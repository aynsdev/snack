<?php

namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * What an agent connector may read on behalf of the people it is answering.
 *
 * The connector acts as its owner, but in an @agent conversation the person
 * asking may be somebody else, and the answer lands in a thread other people
 * read. So a read is allowed only when every viewer (the owner plus whoever
 * the daemon names in `as_seen_by`) can see the source, and, when an audience
 * channel is named, only when that audience can too:
 *
 * - the audience channel itself always passes;
 * - otherwise only non-private channels pass, and only when no guest is in the
 *   audience (a guest cannot preview public channels);
 * - direct messages pass only when the owner is the sole viewer and there is no
 *   restricting audience.
 *
 * An audience that is a private channel whose members are all viewers adds no
 * restriction: nobody outside the viewers can read the answer.
 */
final readonly class AgentReadScope
{
    public const VIEWERS = 'as_seen_by';

    public const AUDIENCE = 'audience_channel_id';

    private const MAX_VIEWERS = 10;

    /**
     * @param  array<int, array{user: User, role: WorkspaceRole}>  $viewers  keyed by user id, the owner included
     * @param  list<int>  $audienceGuestIds
     */
    private function __construct(
        public Workspace $workspace,
        public User $owner,
        private array $viewers,
        private ?Channel $audience,
        private bool $restrictsToAudience,
        private array $audienceGuestIds,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function requested(array $arguments): bool
    {
        return array_key_exists(self::VIEWERS, $arguments) || array_key_exists(self::AUDIENCE, $arguments);
    }

    /**
     * The scope for these arguments, or why they are not acceptable.
     */
    public static function resolve(Workspace $workspace, User $owner, mixed $viewerIds, mixed $audienceId): self|string
    {
        $viewers = self::resolveViewers($workspace, $owner, $viewerIds);

        if ($viewers === null) {
            return 'as_seen_by must be a list of at most '.self::MAX_VIEWERS.' user ids of active members of this workspace.';
        }

        if ($audienceId === null) {
            return new self($workspace, $owner, $viewers, null, false, []);
        }

        $audience = is_int($audienceId)
            ? Channel::query()->where('workspace_id', $workspace->id)->find($audienceId)
            : null;

        if ($audience === null || ! self::channelVisibleToAll($audience, $viewers)) {
            return 'audience_channel_id must be a channel in this workspace that every viewer can see.';
        }

        $memberIds = $audience->members()->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all();
        $restricts = ! $audience->is_private || array_diff($memberIds, array_keys($viewers)) !== [];

        $guestIds = $restricts
            ? array_values(WorkspaceMember::query()
                ->where('workspace_id', $workspace->id)
                ->whereRole(WorkspaceRole::Guest)
                ->whereIn('user_id', $memberIds)
                ->pluck('user_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all())
            : [];

        return new self($workspace, $owner, $viewers, $audience, $restricts, $guestIds);
    }

    /**
     * Everyone the answer is for, the owner first.
     *
     * @return list<User>
     */
    public function viewers(): array
    {
        return array_values(array_map(fn (array $viewer): User => $viewer['user'], $this->viewers));
    }

    /**
     * Whether everyone the answer is for may browse the member directory,
     * which guests may not.
     */
    public function allowsMemberDirectory(): bool
    {
        foreach ($this->viewers as $viewer) {
            if ($viewer['role']->isGuest()) {
                return false;
            }
        }

        return $this->audienceGuestIds === [];
    }

    public function allowsDirectMessages(): bool
    {
        return count($this->viewers) === 1 && ! $this->restrictsToAudience;
    }

    public function allowsMessage(Message $message): bool
    {
        $container = $message->container();

        return match (true) {
            $container instanceof Channel => $this->allowsChannel($container),
            $container instanceof Conversation => $this->allowsDirectMessages(),
            default => false,
        };
    }

    public function allowsChannel(Channel $channel): bool
    {
        return $this->constrainChannels(Channel::query()->whereKey($channel->id))->exists();
    }

    public function allowsProject(Project $project): bool
    {
        return $this->constrainProjects(Project::query()->whereKey($project->id))->exists();
    }

    /**
     * Whether the place a file lives in passes this scope. Who may read the
     * file itself is {@see App\Ai\BiteFiles}'s to decide, per viewer.
     */
    public function allowsAttachment(MessageAttachment $file): bool
    {
        if ($file->asset_id !== null && $this->audienceGuestIds === []) {
            return true;
        }

        if ($file->message_id !== null) {
            $message = Message::query()->where('workspace_id', $this->workspace->id)->find($file->message_id);

            return $message !== null && $this->allowsMessage($message);
        }

        if ($file->task_id !== null) {
            $task = Task::query()->with('project')->find($file->task_id);

            return $task !== null && $this->allowsProject($task->project);
        }

        return false;
    }

    /**
     * @param  Builder<Channel>  $channels
     * @return Builder<Channel>
     */
    public function constrainChannels(Builder $channels): Builder
    {
        $channels->where('workspace_id', $this->workspace->id);

        foreach ($this->viewers as $viewer) {
            $channels->visibleTo($viewer['user'], $viewer['role']);
        }

        if ($this->restrictsToAudience && $this->audience !== null) {
            $audienceId = $this->audience->id;
            $guestFree = $this->audienceGuestIds === [];

            $channels->where(fn (Builder $allowed) => $allowed
                ->whereKey($audienceId)
                ->when($guestFree, fn (Builder $open) => $open->orWhere('is_private', false)));
        }

        return $channels;
    }

    /**
     * Narrow a query over `projects` to the boards every viewer can see.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $projects
     * @return Builder<TModel>
     */
    public function constrainProjects(Builder $projects): Builder
    {
        $projects->where('workspace_id', $this->workspace->id);

        $guestIds = [
            ...array_keys(array_filter($this->viewers, fn (array $viewer): bool => $viewer['role']->isGuest())),
            ...$this->audienceGuestIds,
        ];

        foreach (array_unique($guestIds) as $guestId) {
            $projects->whereHas('members', fn (Builder $members) => $members->where('user_id', $guestId));
        }

        return $projects;
    }

    /**
     * Narrow a query over `messages` to the containers this scope allows.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $messages
     * @return Builder<TModel>
     */
    public function constrainMessages(Builder $messages): Builder
    {
        $channels = $this->constrainChannels(Channel::query())->select('id');
        $conversations = Conversation::query()
            ->where('workspace_id', $this->workspace->id)
            ->visibleTo($this->owner)
            ->select('id');

        return $messages->where(fn (Builder $visible) => $visible
            ->whereIn('channel_id', $channels)
            ->when($this->allowsDirectMessages(), fn (Builder $direct) => $direct->orWhereIn('conversation_id', $conversations)));
    }

    /**
     * The owner plus the named members, keyed by user id, or null when the
     * list is malformed or names somebody who is not an active member.
     *
     * @return array<int, array{user: User, role: WorkspaceRole}>|null
     */
    private static function resolveViewers(Workspace $workspace, User $owner, mixed $ids): ?array
    {
        $ownerRole = $owner->roleIn($workspace);

        if ($ownerRole === null) {
            return null;
        }

        $ids ??= [];

        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > self::MAX_VIEWERS) {
            return null;
        }

        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                return null;
            }
        }

        $others = array_values(array_diff(array_unique($ids), [$owner->id]));
        $viewers = [$owner->id => ['user' => $owner, 'role' => $ownerRole]];

        if ($others === []) {
            return $viewers;
        }

        $users = User::query()->whereKey($others)->get();

        if ($users->count() !== count($others)) {
            return null;
        }

        foreach ($users as $user) {
            $role = $user->isBot() ? null : $user->roleIn($workspace);

            if ($role === null) {
                return null;
            }

            $viewers[$user->id] = ['user' => $user, 'role' => $role];
        }

        return $viewers;
    }

    /**
     * @param  array<int, array{user: User, role: WorkspaceRole}>  $viewers
     */
    private static function channelVisibleToAll(Channel $channel, array $viewers): bool
    {
        $query = Channel::query()->whereKey($channel->id);

        foreach ($viewers as $viewer) {
            $query->visibleTo($viewer['user'], $viewer['role']);
        }

        return $query->exists();
    }
}
