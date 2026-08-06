<?php

namespace App\Models;

use App\Support\Positions;
use Database\Factories\ProjectGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named shelf of projects inside one workspace.
 *
 * Addressed by id rather than slug: a group has no page of its own, so there is
 * nothing for a slug to carry, and a name people rename freely makes a poor
 * route key.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $name
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'created_by', 'name', 'position'])]
class ProjectGroup extends Model
{
    /** @use HasFactory<ProjectGroupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
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
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    // -- Ordering ------------------------------------------------------------

    /**
     * Where a new group goes: after the last one there is.
     */
    public static function nextPosition(Workspace $workspace): int
    {
        $last = static::query()
            ->where('workspace_id', $workspace->id)
            ->max('position');

        return $last === null ? Positions::GAP : $last + Positions::GAP;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        // Tie-broken by id so two groups that somehow share a position still
        // draw in a stable order rather than swapping between requests.
        $query->orderBy('position')->orderBy('id');
    }
}
