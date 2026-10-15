<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One card.
 *
 * Its two placements are independent: `board_column_id` is the status it has
 * reached and is never null, `sprint_id` is when it is being done and null means
 * the backlog. See the tasks migration for why collapsing those into one column
 * loses the thing a sprint rollover needs.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number
 * @property int $board_column_id
 * @property int|null $sprint_id
 * @property int|null $release_id
 * @property int|null $parent_task_id
 * @property TaskType $type
 * @property TaskPriority $priority
 * @property string $title
 * @property string|null $description
 * @property int|null $assignee_id
 * @property int|null $reporter_id
 * @property int $position
 * @property int|null $story_points
 * @property Carbon|null $due_on
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read BoardColumn $column
 * @property-read Collection<int, TaskComment> $comments
 * @property-read Collection<int, MessageAttachment> $attachments
 * @property-read Collection<int, Task> $children
 * @property-read int|null $subtasks_count  present when the card data was loaded
 * @property-read int|null $completed_subtasks_count  likewise
 * @property-read Collection<int, TaskLink> $links
 * @property-read Collection<int, TaskRelation> $outgoingRelations
 * @property-read Collection<int, TaskRelation> $incomingRelations
 */
#[Fillable([
    'project_id', 'number', 'board_column_id', 'sprint_id', 'release_id', 'parent_task_id',
    'type', 'priority', 'title', 'description', 'assignee_id', 'reporter_id',
    'position', 'story_points', 'due_on', 'completed_at',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'priority' => TaskPriority::class,
            'number' => 'integer',
            'position' => 'integer',
            'story_points' => 'integer',
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<BoardColumn, $this>
     */
    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }

    /**
     * @return BelongsTo<Sprint, $this>
     */
    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    /**
     * The version this goes out in, or null for work nobody has scheduled.
     *
     * A second, independent placement alongside `sprint` — when a team works on
     * something and when it ships are different questions.
     *
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function epic(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    /**
     * Web links on the card: pull requests, designs, incidents.
     *
     * @return HasMany<TaskLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(TaskLink::class)->orderBy('id');
    }

    /**
     * Relations said from this card: "this blocks PWA-2".
     *
     * @return HasMany<TaskRelation, $this>
     */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(TaskRelation::class)->orderBy('id');
    }

    /**
     * Relations said from another card about this one: "PWA-1 blocks this".
     *
     * @return HasMany<TaskRelation, $this>
     */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(TaskRelation::class, 'related_task_id')->orderBy('id');
    }

    /**
     * Whether `$taskId` is this task, or anywhere above it — the check that
     * stops a task being put under one of its own subtasks.
     *
     * Walks up one parent at a time, bounded so a loop already in the data
     * cannot spin forever; past the bound it answers true, refusing the move.
     */
    public function isOrDescendsFrom(int $taskId): bool
    {
        $currentId = $this->id;

        for ($depth = 0; $depth < 50 && $currentId !== null; $depth++) {
            if ($currentId === $taskId) {
                return true;
            }

            $currentId = static::query()->whereKey($currentId)->value('parent_task_id');
        }

        return $currentId !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * The discussion on this card, oldest first.
     *
     * Never eager-loaded by the board: eighty cards' worth of discussion is a
     * payload nobody is reading. It arrives only with the `tasks.show` endpoint,
     * which is what the panel asks for when a card is opened.
     *
     * @return HasMany<TaskComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->ordered();
    }

    /**
     * Files attached to this card — the same table, storage and download route
     * as a message's files. See the attachments migration for why there is not a
     * second one.
     *
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('id');
    }

    /**
     * @return BelongsToMany<TaskLabel, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(TaskLabel::class, 'task_label_task');
    }

    // -- Reading -------------------------------------------------------------

    /**
     * The reference this task is quoted by — "PWA-1499".
     *
     * Reads the loaded project when there is one so a board of eighty cards does
     * not issue eighty queries to spell its own keys.
     */
    public function reference(): string
    {
        $key = $this->relationLoaded('project')
            ? $this->project->key
            : $this->project()->value('key');

        return "{$key}-{$this->number}";
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function isInBacklog(): bool
    {
        return $this->sprint_id === null;
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * The backlog: everything not committed to a sprint.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInBacklog(Builder $query): void
    {
        $query->whereNull('sprint_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * Everything the board and the backlog need to draw a card, in one query.
     *
     * A board is the one read in this feature that fans out over relations —
     * assignee, labels, epic — and a card is drawn per row, so anything not
     * loaded here becomes an N+1 the moment somebody has a busy board.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWithCardData(Builder $query): void
    {
        $query->with([
            'assignee',
            'labels',
            'epic:id,project_id,number,title,type',
            'release:id,project_id,name',
        ])->withCount([
            'children as subtasks_count',
            'children as completed_subtasks_count' => fn (Builder $children) => $children->whereNotNull('completed_at'),
        ]);
    }
}
