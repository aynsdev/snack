<?php

namespace App\Models;

use App\Enums\OutboundEventStatus;
use App\Enums\OutboundEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One event waiting for, or done with, delivery to the agent dispatcher.
 *
 * See the `create_outbound_events_table` migration for why it is a table, and
 * {@see App\Services\Outbound\OutboundDelivery} for how it leaves.
 *
 * `payload` holds the envelope minus `event_id`, `sequence` and `type`, which
 * are this row's own columns; {@see envelope()} puts them back together.
 *
 * Pruned once it has served its purpose — see {@see prunable()} — because the
 * payloads carry message bodies and task descriptions, and keeping a second
 * copy of those for ever outlives every deletion a person makes in Snack.
 *
 * @property int $id
 * @property string $event_id
 * @property int $workspace_id
 * @property int $sequence
 * @property OutboundEventType $type
 * @property array<string, mixed> $payload
 * @property OutboundEventStatus $status
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon|null $dispatched_at
 * @property string|null $last_error
 * @property int|null $last_status
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'event_id', 'workspace_id', 'sequence', 'type', 'payload', 'status', 'attempts', 'available_at',
    'dispatched_at', 'last_error', 'last_status', 'delivered_at',
])]
class OutboundEvent extends Model
{
    use MassPrunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OutboundEventType::class,
            'payload' => 'array',
            'status' => OutboundEventStatus::class,
            'workspace_id' => 'integer',
            'sequence' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'last_status' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * The body that is signed and sent.
     *
     * Rebuilt in a fixed key order because `jsonb` does not keep the order it
     * was given. Every attempt therefore sends the same bytes, which is what
     * lets a receiver treat a retry as the same event.
     *
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        return [
            'event_id' => $this->event_id,
            'sequence' => $this->sequence,
            'type' => $this->type->value,
            'schema_version' => $this->payload['schema_version'] ?? 1,
            'occurred_at' => $this->payload['occurred_at'] ?? null,
            'instance' => $this->payload['instance'] ?? null,
            'workspace' => $this->payload['workspace'] ?? null,
            'actor' => $this->payload['actor'] ?? null,
            'data' => $this->payload['data'] ?? null,
        ];
    }

    /**
     * Delivered a week ago, or given up on a month ago. Long enough to answer
     * "did the dispatcher get it?", short enough that a deleted message's body
     * does not linger in a table nobody reads.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(fn (Builder $query) => $query
                ->where('status', OutboundEventStatus::Delivered)
                ->where('delivered_at', '<', now()->subDays(7)))
            ->orWhere(fn (Builder $query) => $query
                ->where('status', OutboundEventStatus::Dead)
                ->where('updated_at', '<', now()->subDays(30)));
    }

    /**
     * Pending and due now.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', OutboundEventStatus::Pending)->where('available_at', '<=', now());
    }
}
