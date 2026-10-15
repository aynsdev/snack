<?php

namespace App\Models;

use Database\Factories\TaskLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A web link on a card — a pull request, a design, an incident.
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $created_by
 * @property string $url
 * @property string|null $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $task
 * @property-read User|null $creator
 */
#[Fillable(['task_id', 'created_by', 'url', 'title'])]
class TaskLink extends Model
{
    /** @use HasFactory<TaskLinkFactory> */
    use HasFactory;

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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What to show for the link: its title, or the URL without the scheme.
     */
    public function label(): string
    {
        return $this->title ?? preg_replace('#^https?://#', '', $this->url);
    }
}
