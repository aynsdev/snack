<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 */
#[Fillable([
    'project_id', 'number', 'board_column_id', 'sprint_id', 'parent_task_id',
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
        $query->with(['assignee', 'labels', 'epic:id,project_id,number,title,type']);
    }
}
