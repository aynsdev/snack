<?php

namespace App\Http\Resources;

use App\Models\Sprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sprint, with the two kinds of date kept apart.
 *
 * `starts_at` / `ends_at` are what the team planned; `started_at` /
 * `completed_at` are what happened. `state` is derived from the second pair —
 * see {@see Sprint::state()} — and is the only place the client should read it
 * from, so the badge on the backlog cannot disagree with the board.
 *
 * Mirrors the `Sprint` type in resources/js/types/snack.ts.
 *
 * @mixin Sprint
 */
class SprintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'goal' => $this->goal,
            'state' => $this->state()->value,
            'is_overdue' => $this->isOverdue(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'position' => $this->position,
            'task_count' => $this->whenCounted('tasks'),
        ];
    }
}
