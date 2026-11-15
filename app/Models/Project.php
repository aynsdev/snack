<?php

namespace App\Models;

use App\Enums\ProjectRole;
use App\Policies\ProjectPolicy;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A board, a backlog, and the people who work them.
 *
 * Addressed by slug like a channel, and referenced by `key` like a Jira project:
 * the slug is what the URL carries, the key is what people type into a message.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property int|null $project_group_id
 * @property string $name
 * @property string $slug
 * @property string $key
 * @property string|null $description
 * @property string|null $repository_url
 * @property string|null $repository_base_branch
 * @property string $color
 * @property bool $uses_sprints
 * @property bool $is_archived
 * @property int $next_task_number
 * @property array<string, mixed> $card_fields
 * @property int|null $agent_channel_id
 * @property int|null $agent_in_progress_column_id
 * @property int|null $agent_review_column_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable([
    'workspace_id', 'created_by', 'project_group_id', 'name', 'slug', 'key',
    'description', 'repository_url', 'repository_base_branch', 'color', 'uses_sprints', 'is_archived', 'card_fields',
    'next_task_number', 'agent_channel_id', 'agent_in_progress_column_id',
    'agent_review_column_id',
])]
#[RouteKey('slug')]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * The settings that point this project's agents somewhere. Agents are on
     * while the channel is set and usable — see {@see agentsEnabled()}.
     *
     * @var array<int, string>
     */
    public const AGENT_SETTINGS = [
        'agent_channel_id', 'agent_in_progress_column_id', 'agent_review_column_id',
    ];

    /**
     * Everything a project member may change to set its agents up: the agent
     * settings above, and the repository they work in. A save touching only
     * these needs {@see ProjectPolicy::configureAgents()} rather than a lead.
     *
     * @var array<int, string>
     */
    public const AGENT_CONFIGURATION = [
        ...self::AGENT_SETTINGS, 'repository_url', 'repository_base_branch',
    ];

    /**
     * What a card shows, when the project has not said otherwise.
     *
     * Read through {@see cardFields()} rather than merged on write, so a toggle
     * added in a later release appears on every existing project instead of
     * silently defaulting to false for all of them.
     *
     * @var array<string, bool>
     */
    public const CARD_FIELD_DEFAULTS = [
        'type' => true,
        'key' => true,
        'priority' => true,
        'assignee' => true,
        'labels' => true,
        'story_points' => false,
        'due_on' => false,
        'epic' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uses_sprints' => 'boolean',
            'is_archived' => 'boolean',
            'next_task_number' => 'integer',
            'card_fields' => 'array',
            'agent_channel_id' => 'integer',
            'agent_in_progress_column_id' => 'integer',
            'agent_review_column_id' => 'integer',
        ];
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The shelf this project sits on, or null when it sits on none.
     *
     * Null is the ordinary case rather than an unset one: grouping is something
     * a workspace opts into once it has enough projects to need it.
     *
     * @return BelongsTo<ProjectGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProjectGroup::class, 'project_group_id');
    }

    /**
     * @return HasMany<ProjectMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->using(ProjectMember::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<BoardColumn, $this>
     */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position');
    }

    /**
     * @return HasMany<Sprint, $this>
     */
    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<TaskLabel, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(TaskLabel::class)->orderBy('name');
    }

    /**
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * Where this project's agents post their threads, and where the people
     * working with them reply.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function agentChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'agent_channel_id');
    }

    /**
     * @return BelongsTo<BoardColumn, $this>
     */
    public function agentInProgressColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'agent_in_progress_column_id');
    }

    /**
     * @return BelongsTo<BoardColumn, $this>
     */
    public function agentReviewColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'agent_review_column_id');
    }

    // -- Membership ----------------------------------------------------------

    public function membershipFor(User $user): ?ProjectMember
    {
        if ($this->relationLoaded('members')) {
            return $this->members->firstWhere('user_id', $user->id);
        }

        return $this->members()->where('user_id', $user->id)->first();
    }

    public function hasMember(User $user): bool
    {
        return $this->membershipFor($user) !== null;
    }

    public function roleFor(User $user): ?ProjectRole
    {
        return $this->membershipFor($user)?->role;
    }

    /**
     * Whether this person keeps this project at the top of their sidebar.
     *
     * Read off the membership, which the sidebar has already loaded — see the
     * `starred_at` migration for why the star lives there rather than in a
     * table of its own.
     */
    public function isStarredBy(User $user): bool
    {
        return $this->membershipFor($user)?->starred_at !== null;
    }

    // -- Agents --------------------------------------------------------------

    /**
     * Whether agents work this project right now: a channel of this
     * workspace is set, and neither the project nor that channel is archived.
     * Archiving either is a way of switching agents off without losing which
     * channel they used.
     */
    public function agentsEnabled(): bool
    {
        $channel = $this->agentChannel;

        return $this->agent_channel_id !== null
            && ! $this->is_archived
            && $channel?->is_archived === false
            && $channel->workspace_id === $this->workspace_id;
    }

    /**
     * Whether this person may point the project's agents at the channel: a
     * live, non-announcement channel of this workspace that they can see.
     */
    public function acceptsAgentChannelFrom(User $user, int $channelId): bool
    {
        $role = $user->roleIn($this->workspace);

        if ($role === null) {
            return false;
        }

        return Channel::query()
            ->whereKey($channelId)
            ->where('workspace_id', $this->workspace_id)
            ->choosableAsAgentChannel($user, $role)
            ->exists();
    }

    // -- Board ---------------------------------------------------------------

    /**
     * The sprint the board is currently drawing, or null when there is none.
     *
     * Null is an ordinary state, not an error: a kanban project never has one,
     * and a scrum project between sprints has finished the last and not yet
     * started the next. Both render the same way — see {@see boardTasks()}.
     */
    public function activeSprint(): ?Sprint
    {
        if ($this->relationLoaded('sprints')) {
            return $this->sprints->first(fn (Sprint $sprint): bool => $sprint->isActive());
        }

        return $this->sprints()->active()->first();
    }

    /**
     * What the board shows.
     *
     * A scrum project shows the active sprint and nothing else — that is the
     * commitment the team made, and putting the backlog on the board would undo
     * it. A kanban project has no sprints at all, so the board is everything
     * that is not finished and archived away.
     *
     * @return Builder<Task>
     */
    public function boardTasks(): Builder
    {
        $query = $this->tasks()->getQuery();

        if (! $this->uses_sprints) {
            return $query;
        }

        $sprint = $this->activeSprint();

        // No active sprint means an empty board rather than the backlog: the
        // board answers "what are we working on now", and the honest answer
        // during planning is "nothing yet".
        return $query->where('sprint_id', $sprint?->id ?? 0);
    }

    /**
     * Which fields the cards on this board draw, defaults filled in.
     *
     * @return array<string, bool>
     */
    public function cardFields(): array
    {
        $stored = array_filter(
            $this->card_fields,
            static fn (mixed $value, string $key): bool => is_bool($value)
                && array_key_exists($key, self::CARD_FIELD_DEFAULTS),
            ARRAY_FILTER_USE_BOTH,
        );

        return [...self::CARD_FIELD_DEFAULTS, ...$stored];
    }

    /**
     * The reference a task in this project is quoted by — "PWA-1499".
     */
    public function reference(int $number): string
    {
        return "{$this->key}-{$number}";
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeNotArchived(Builder $query): void
    {
        $query->where('is_archived', false);
    }

    /**
     * The query form of {@see agentsEnabled()}.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWithAgentsEnabled(Builder $query): void
    {
        $query->notArchived()->whereHas('agentChannel', fn (Builder $channel) => $channel
            ->where('channels.is_archived', false)
            ->whereColumn('channels.workspace_id', 'projects.workspace_id'));
    }

    /**
     * The projects a person can see: the ones they are on.
     *
     * Deliberately membership rather than the whole workspace. A project is a
     * working surface, not a directory — see {@see App\Policies\ProjectPolicy},
     * which is the authoritative version of this rule and lets workspace admins
     * through as well so an unowned project is never stranded.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWhereMember(Builder $query, User $user): void
    {
        $query->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
    }
}
