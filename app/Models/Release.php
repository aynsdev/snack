<?php

namespace App\Models;

use App\Enums\ReleaseState;
use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A version of one project, and the work that ships in it.
 *
 * A release is *what goes out*, where a sprint is *when a team works* — see the
 * releases migration for why a task carries both and neither stands in for the
 * other.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $notes
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read int|null $tasks_count  present when the query counted them
 * @property-read int|null $completed_tasks_count  likewise; see ReleaseController
 */
#[Fillable(['project_id', 'name', 'notes', 'starts_on', 'ends_on', 'released_at'])]
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'released_at' => 'datetime',
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
     * Whether this has gone out, and when it says so.
     *
     * The only definition — there is no status column to disagree with the
     * timestamp, the same choice {@see Sprint::state()} makes.
     */
    public function state(): ReleaseState
    {
        return $this->released_at === null
            ? ReleaseState::Unreleased
            : ReleaseState::Released;
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /**
     * The day it was meant to go out has passed and it has not gone out.
     *
     * Deliberately not "has unfinished work in it": a version can ship with
     * things cut, and one whose work is all done but which nobody has released
     * is still late. Whether the contents are finished is the progress bar's
     * question, and it is asked separately. Costs no query either way.
     */
    public function isOverdue(): bool
    {
        return $this->ends_on !== null
            && $this->ends_on->isPast()
            && ! $this->isReleased();
    }

    /**
     * Soonest first, so the releases table reads as a runway: what is going out
     * next is at the top. Undated releases sort after the dated ones — they are
     * ideas for a version rather than a plan for one.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw('ends_on IS NULL')
            ->orderBy('ends_on')
            ->orderBy('id');
    }
}
