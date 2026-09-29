<?php

namespace App\Support;

use App\Models\Workspace;

/**
 * The circle a workspace expects people to clock in from.
 *
 * Read from the workspace's `hr.geofence` setting. A workspace with no office
 * set has no geofence, and every clock-in is then recorded without a verdict.
 */
final readonly class Geofence
{
    private const float EARTH_RADIUS_METERS = 6_371_000;

    public function __construct(
        public float $latitude,
        public float $longitude,
        public int $radiusMeters,
        public bool $enforced = false,
    ) {}

    public static function forWorkspace(Workspace $workspace): ?self
    {
        $latitude = $workspace->setting('hr.geofence.latitude');
        $longitude = $workspace->setting('hr.geofence.longitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        return new self(
            latitude: (float) $latitude,
            longitude: (float) $longitude,
            radiusMeters: (int) $workspace->setting('hr.geofence.radius_meters', 200),
            enforced: (bool) $workspace->setting('hr.geofence.enforced', false),
        );
    }

    /**
     * Great-circle distance from the centre, by the haversine formula.
     */
    public function distanceTo(float $latitude, float $longitude): float
    {
        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($latitude);
        $deltaLat = $latTo - $latFrom;
        $deltaLng = deg2rad($longitude - $this->longitude);

        $a = sin($deltaLat / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($deltaLng / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function contains(float $latitude, float $longitude): bool
    {
        return $this->distanceTo($latitude, $longitude) <= $this->radiusMeters;
    }

    /**
     * @return array{latitude: float, longitude: float, radius_meters: int, enforced: bool}
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'radius_meters' => $this->radiusMeters,
            'enforced' => $this->enforced,
        ];
    }
}
