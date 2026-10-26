<?php

namespace App\Models;

use Database\Factories\TaskCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One remark on a card.
 *
 * Plain text, stored as typed. The composer's formatting markers belong to
 * messages, which have a parser, a mention pipeline, and a search vector behind
 * them; a comment has none of those and pretending otherwise would render
 * `**bold**` literally to whoever reads it next.
 *
 * `author` is nullable: a deleted account leaves its comments behind, because
 * the reasoning recorded on a card is what the next person needs and losing it
 * with the person is worse than showing it unattributed.
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $author_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property array{connector_id: string, name: string|null}|null $agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $task
 * @property-read User|null $author
 */
#[Fillable(['task_id', 'author_id', 'body', 'edited_at', 'agent'])]
class TaskComment extends Model
{
    /** @use HasFactory<TaskCommentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'agent' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Oldest first — a discussion is read in the order it happened, unlike a
     * timeline, which is joined at the end.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('created_at')->orderBy('id');
    }
}
