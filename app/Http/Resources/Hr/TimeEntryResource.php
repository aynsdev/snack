<?php

namespace App\Http\Resources\Hr;

use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TimeEntry
 */
class TimeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn (): array => [
                'id' => $this->employee->id,
                'name' => $this->employee->user->name,
                'initials' => $this->employee->user->initials(),
                'avatar_url' => $this->employee->user->avatarUrl(),
            ]),
            'clock_in_at' => $this->clock_in_at->toIso8601String(),
            'clock_out_at' => $this->clock_out_at?->toIso8601String(),
            'minutes' => $this->minutesWorked(),
            'is_open' => $this->isOpen(),
            'clock_in_location' => $this->location('clock_in'),
            'clock_out_location' => $this->location('clock_out'),
            'note' => $this->note,
        ];
    }

    /**
     * @return array{latitude: float, longitude: float, accuracy: float|null, within_geofence: bool|null}|null
     */
    private function location(string $end): ?array
    {
        $latitude = $this->resource->{"{$end}_latitude"};
        $longitude = $this->resource->{"{$end}_longitude"};

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'accuracy' => $this->resource->{"{$end}_accuracy"},
            'within_geofence' => $this->resource->{"{$end}_within_geofence"},
        ];
    }
}
