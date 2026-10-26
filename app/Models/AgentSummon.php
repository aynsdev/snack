<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An `@agent` summons in a channel thread — see the agent_summons migration.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int $thread_root_id
 * @property int $target_user_id
 * @property int $summoner_id
 * @property int $message_id
 * @property Carbon|null $created_at
 * @property Carbon|null $closed_at
 */
#[Fillable([
    'workspace_id', 'channel_id', 'thread_root_id', 'target_user_id', 'summoner_id', 'message_id', 'closed_at',
])]
class AgentSummon extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
