<?php

namespace App\Models;

use App\Enums\TaskRelationType;
use Database\Factories\TaskRelationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One card's relationship to another, stored once and read from both sides.
 * See the task_relations migration.
 *
 * @property int $id
 * @property int $task_id
 * @property int $related_task_id
 * @property int|null $created_by
 * @property TaskRelationType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $task
 * @property-read Task $relatedTask
 */
#[Fillable(['task_id', 'related_task_id', 'created_by', 'type'])]
class TaskRelation extends Model
{
    /** @use HasFactory<TaskRelationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskRelationType::class,
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
     * @return BelongsTo<Task, $this>
     */
    public function relatedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'related_task_id');
    }
}
