<?php

namespace App\Http\Resources;

use App\Models\Standup;
use App\Models\StandupRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A standup as the admin screen sees it.
 *
 * Mirrors the `Standup` type in resources/js/types/snack.ts.
 *
 * `schedule_summary` is rendered server-side rather than assembled in the
 * client from the day numbers and the time. The client would need the same
 * "Weekdays"/"Every day" collapsing and the same weekday names to say the same
 * sentence, and two implementations of one sentence is how the list and the
 * edit dialog end up describing the same standup differently.
 *
 * @mixin Standup
 */
class StandupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latest = $this->resource->relationLoaded('runs')
            ? $this->resource->runs->first()
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,

            'channel' => [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
                'slug' => $this->channel->slug,
            ],

            'questions' => $this->resource->questionList(),
            'days_of_week' => $this->resource->weekdays(),

            // "09:30" — the TIME column arrives from Postgres with seconds on
            // it, and the form that posts it back offers minutes.
            'ask_at' => $this->resource->askAtLabel(),
            'timezone' => $this->timezone,

            'reminder_after_minutes' => $this->reminder_after_minutes,
            'closes_after_minutes' => $this->closes_after_minutes,
            'is_active' => $this->is_active,

            'schedule_summary' => $this->resource->scheduleSummary(),

            /*
             * How many people this actually messages, which is the number an
             * admin is really checking when they open this screen — a standup
             * pointed at a channel of one is a mistake that looks fine until
             * somebody counts.
             *
             * Loaded by the controller as an aggregate; absent rather than
             * lazily counted here, because that would be a query per row.
             */
            'participant_count' => $this->whenNotNull($this->participant_count),

            'last_run' => $latest instanceof StandupRun ? [
                'date' => $latest->scheduled_for->toDateString(),
                'date_label' => $latest->dateLabel(),
                'state' => $latest->state->value,
                'asked_at' => $latest->asked_at?->toIso8601String(),
                'closed_at' => $latest->closed_at?->toIso8601String(),
                'answered' => (int) ($latest->answered_count ?? 0),
                'asked' => (int) ($latest->responses_count ?? 0),
            ] : null,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
