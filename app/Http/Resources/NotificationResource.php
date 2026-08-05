<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the notification centre.
 *
 * The `data` payload is spread rather than nested, so the shape here is exactly
 * the shape the broadcast carries — see
 * {@see App\Notifications\MessageNotification::toBroadcast()}. That is what lets
 * the client prepend an arriving notification to the list it already rendered
 * without a second, subtly different type to convert between.
 *
 * Mirrors the `NotificationEntry` type in resources/js/types/snack.ts.
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The reason, which is what this table's `type` column holds.
            'type' => $this->type,
            'is_read' => $this->isRead(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // How many messages this row stands for. Above 1 only for a reason
            // that coalesces — see App\Services\NotificationCoalescer.
            'count' => (int) data_get($this->data, 'count', 1),

            'message_id' => data_get($this->data, 'message_id'),
            'thread_root_id' => data_get($this->data, 'thread_root_id'),
            'preview' => data_get($this->data, 'preview', ''),
            'actor' => data_get($this->data, 'actor'),
            'container' => data_get($this->data, 'container'),

            /*
             * Present only on a task assignment, which is the one reason that is
             * not about a message and so has no container to link through. The
             * row builds its own link from this — see
             * {@see App\Notifications\TaskAssignedNotification}.
             */
            'task' => data_get($this->data, 'task'),
        ];
    }
}
