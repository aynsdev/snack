<?php

// CarbonImmutable rather than Carbon: see the note in App\Models\Huddle.

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BiteMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fact an admin has told Bite.
 *
 * See the table migration for why every one of these is put into the prompt of
 * every answer rather than searched, and what that costs.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $title
 * @property string $body
 * @property bool $is_enabled
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $author
 */
#[Fillable(['workspace_id', 'created_by', 'title', 'body', 'is_enabled', 'position'])]
class BiteMemory extends Model
{
    /** @use HasFactory<BiteMemoryFactory> */
    use HasFactory;

    /**
     * The gap left between entries, so one can be dropped between two
     * neighbours without renumbering the list. Same scheme as the boards.
     */
    public const POSITION_GAP = 100;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The entries that actually reach the model.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('is_enabled', true);
    }

    /**
     * In the order the admin arranged them, with the id as the tiebreak so two
     * entries that were given the same position do not swap places between one
     * page load and the next.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * The position a new entry goes in: after everything already there.
     */
    public static function nextPosition(Workspace $workspace): int
    {
        $last = static::query()
            ->where('workspace_id', $workspace->id)
            ->max('position');

        return ((int) $last) + self::POSITION_GAP;
    }
}
