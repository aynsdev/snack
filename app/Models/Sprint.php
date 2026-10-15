<?php

namespace App\Models;

use App\Enums\SprintState;
use Database\Factories\SprintFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A fixed window of work in one project.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $goal
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read int|null $tasks_count  present when the query counted them
 * @property-read int|null $completed_tasks_count  likewise
 */
#[Fillable([
    'project_id', 'name', 'goal', 'starts_at', 'ends_at',
    'started_at', 'completed_at', 'position',
])]
class Sprint extends Model
{
    /** @use HasFactory<SprintFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Where this sprint is in its life, derived from the two timestamps.
     *
     * The only definition — there is no `state` column to disagree with it.
     */
    public function state(): SprintState
    {
        if ($this->completed_at !== null) {
            return SprintState::Completed;
        }

        return $this->started_at !== null
            ? SprintState::Active
            : SprintState::Planned;
    }

    public function isActive(): bool
    {
        return $this->state() === SprintState::Active;
    }

    public function isCompleted(): bool
    {
        return $this->state() === SprintState::Completed;
    }

    /**
     * Whether the sprint has run past the date it was meant to end.
     *
     * Only ever true while it is running: a completed sprint that took three
     * days longer than planned is a fact about the past, not a warning.
     */
    public function isOverdue(): bool
    {
        return $this->isActive()
            && $this->ends_at !== null
            && $this->ends_at->isPast();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('started_at')->whereNull('completed_at');
    }

    /**
     * Sprints still ahead of the team: planned, or running.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
