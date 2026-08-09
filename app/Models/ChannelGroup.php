<?php

namespace App\Models;

use App\Support\Positions;
use Database\Factories\ChannelGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's heading in their own channel list.
 *
 * Unlike {@see ProjectGroup}, which the whole workspace shares, a channel group
 * belongs to the member who made it: the sidebar lists the channels *you*
 * joined, so its arrangement can only be yours. See the migration for why.
 *
 * Addressed by id rather than slug, for the same reason a project group is: it
 * has no page of its own, and a freely renamed name makes a poor route key.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $name
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User $user
 */
#[Fillable(['workspace_id', 'user_id', 'name', 'position'])]
class ChannelGroup extends Model
{
    /** @use HasFactory<ChannelGroupFactory> */
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
     * The member whose sidebar this heading appears in.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The memberships filed under this heading — one per channel the owner put
     * here. The channels themselves are reached through it.
     *
     * @return HasMany<ChannelMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ChannelMember::class);
    }

    // -- Ordering ------------------------------------------------------------

    /**
     * Where a new group goes: after the last one this person has.
     */
    public static function nextPosition(Workspace $workspace, User $user): int
    {
        $last = static::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
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

    /**
     * One person's groups in one workspace — the only way this table is read.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOwnedBy(Builder $query, Workspace $workspace, User $user): void
    {
        $query->where('workspace_id', $workspace->id)->where('user_id', $user->id);
    }
}
