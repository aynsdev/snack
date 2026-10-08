<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\TimeEntry;
use App\Services\Hr\Punctuality;
use App\Support\Geofence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Clocking in and out, geotagged.
 *
 * Each end of a shift records where the browser said the person was and, when
 * the workspace has an office geofence, whether that was inside it. A workspace
 * can require a location at all, and separately refuse clock-ins from outside
 * the fence; without either, the location is recorded for HR to read and
 * nothing is refused.
 */
class ClockTime
{
    public function __construct(private readonly Punctuality $punctuality) {}

    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|null}  $location
     */
    public function in(Employee $employee, array $location, ?string $note = null): TimeEntry
    {
        if ($employee->openTimeEntry()->exists()) {
            throw ValidationException::withMessages(['clock' => 'You are already clocked in.']);
        }

        $stamp = $this->stamp($employee, $location);

        try {
            $entry = $employee->timeEntries()->create([
                'workspace_id' => $employee->workspace_id,
                'clock_in_at' => now(),
                'clock_in_latitude' => $stamp['latitude'],
                'clock_in_longitude' => $stamp['longitude'],
                'clock_in_accuracy' => $stamp['accuracy'],
                'clock_in_within_geofence' => $stamp['within_geofence'],
                'note' => $note,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A double-submitted button racing past the check above.
            throw ValidationException::withMessages(['clock' => 'You are already clocked in.']);
        }

        return $this->punctuality->measure($entry);
    }

    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|null}  $location
     */
    public function out(Employee $employee, array $location, ?string $note = null): TimeEntry
    {
        $entry = $employee->openTimeEntry()->first();

        if ($entry === null) {
            throw ValidationException::withMessages(['clock' => 'You are not clocked in.']);
        }

        $stamp = $this->stamp($employee, $location);

        $entry->update([
            'clock_out_at' => now(),
            'clock_out_latitude' => $stamp['latitude'],
            'clock_out_longitude' => $stamp['longitude'],
            'clock_out_accuracy' => $stamp['accuracy'],
            'clock_out_within_geofence' => $stamp['within_geofence'],
            'note' => $note ?? $entry->note,
        ]);

        return $this->punctuality->measure($entry);
    }

    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy?: float|null}  $location
     * @return array{latitude: float|null, longitude: float|null, accuracy: float|null, within_geofence: bool|null}
     */
    private function stamp(Employee $employee, array $location): array
    {
        $workspace = $employee->workspace;
        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;
        $hasLocation = $latitude !== null && $longitude !== null;

        if (! $hasLocation && (bool) $workspace->setting('hr.require_location', false)) {
            throw ValidationException::withMessages([
                'location' => 'This workspace needs your location to clock in or out. Allow location access and try again.',
            ]);
        }

        $geofence = Geofence::forWorkspace($workspace);
        $within = ($hasLocation && $geofence !== null) ? $geofence->contains($latitude, $longitude) : null;

        if ($geofence?->enforced && $within !== true) {
            throw ValidationException::withMessages([
                'location' => $hasLocation
                    ? sprintf('You are %d m from the office; clocking is only allowed within %d m.', (int) round($geofence->distanceTo($latitude, $longitude)), $geofence->radiusMeters)
                    : 'This workspace only allows clocking from the office. Allow location access and try again.',
            ]);
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $location['accuracy'] ?? null,
            'within_geofence' => $within,
        ];
    }
}
