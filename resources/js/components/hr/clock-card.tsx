import { LoaderCircle, LogIn, LogOut, MapPin, MapPinOff } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { Geofence, TimeEntry } from '@/types/hr';
import { formatDuration, formatMinutes, formatTime, mapsUrl } from './hr-ui';
import type { Position } from './use-clock';
import { useClock, useElapsedSeconds } from './use-clock';

/** Distance in metres between two coordinates (haversine). */
function distanceMeters(
    a: Position,
    b: { latitude: number; longitude: number },
): number {
    const radians = (degrees: number) => (degrees * Math.PI) / 180;
    const deltaLat = radians(b.latitude - a.latitude);
    const deltaLng = radians(b.longitude - a.longitude);
    const h =
        Math.sin(deltaLat / 2) ** 2 +
        Math.cos(radians(a.latitude)) *
            Math.cos(radians(b.latitude)) *
            Math.sin(deltaLng / 2) ** 2;

    return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

/**
 * The time clock: one button that clocks in or out, geotagged.
 *
 * The location is taken fresh at the moment of the click rather than watched
 * continuously — that is the moment the record is about, and a watch would
 * keep the GPS awake for as long as the page is open.
 */
export function ClockCard({
    openEntry,
    todayMinutes,
    geofence,
    requireLocation,
    className,
}: {
    openEntry: TimeEntry | null;
    todayMinutes?: number;
    geofence: Geofence | null;
    requireLocation: boolean;
    className?: string;
}) {
    const [note, setNote] = useState('');
    const isIn = openEntry !== null;
    const { submit, submitting, location, error } = useClock(isIn);
    const runningSeconds = useElapsedSeconds(openEntry?.clock_in_at ?? null);

    const position = location.state === 'located' ? location.position : null;
    const distance =
        position && geofence ? distanceMeters(position, geofence) : null;

    return (
        <section
            className={cn(
                'rounded-xl border border-border bg-card p-4 sm:p-5',
                className,
            )}
            aria-labelledby="time-clock-heading"
        >
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2
                        id="time-clock-heading"
                        className="text-sm font-semibold"
                    >
                        Time clock
                    </h2>
                    <p className="mt-1 text-2xl font-bold tabular-nums">
                        {isIn
                            ? formatDuration(runningSeconds)
                            : 'Not clocked in'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {isIn
                            ? `Since ${formatTime(openEntry.clock_in_at)}`
                            : todayMinutes
                              ? `${formatMinutes(todayMinutes)} logged today`
                              : 'Your location is recorded when you clock in and out.'}
                    </p>
                </div>

                <Button
                    type="button"
                    size="lg"
                    variant={isIn ? 'outline' : 'default'}
                    onClick={() => submit(note, () => setNote(''))}
                    disabled={submitting}
                >
                    {submitting ? (
                        <LoaderCircle
                            aria-hidden="true"
                            className="animate-spin"
                        />
                    ) : isIn ? (
                        <LogOut aria-hidden="true" />
                    ) : (
                        <LogIn aria-hidden="true" />
                    )}
                    {submitting
                        ? location.state === 'locating'
                            ? 'Getting location…'
                            : 'Saving…'
                        : isIn
                          ? 'Clock out'
                          : 'Clock in'}
                </Button>
            </div>

            <div className="mt-3">
                <label htmlFor="clock-note" className="sr-only">
                    Note
                </label>
                <Input
                    id="clock-note"
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                    placeholder={
                        isIn
                            ? 'Note for this shift (optional)'
                            : 'What are you starting on? (optional)'
                    }
                    maxLength={500}
                />
            </div>

            <div className="mt-3 space-y-1 text-xs text-muted-foreground">
                {location.state === 'unavailable' && (
                    <p className="flex items-center gap-1.5">
                        <MapPinOff aria-hidden="true" className="size-3.5" />
                        {location.reason}
                        {requireLocation &&
                            ' This workspace requires a location.'}
                    </p>
                )}

                {position && (
                    <p className="flex flex-wrap items-center gap-1.5">
                        <MapPin aria-hidden="true" className="size-3.5" />
                        <a
                            href={mapsUrl(
                                position.latitude,
                                position.longitude,
                            )}
                            target="_blank"
                            rel="noreferrer"
                            className="underline underline-offset-2"
                        >
                            {position.latitude.toFixed(5)},{' '}
                            {position.longitude.toFixed(5)}
                        </a>
                        <span>±{Math.round(position.accuracy)} m</span>
                        {distance !== null && geofence && (
                            <span
                                className={cn(
                                    'font-medium',
                                    distance <= geofence.radius_meters
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-amber-600 dark:text-amber-400',
                                )}
                            >
                                ·{' '}
                                {distance <= geofence.radius_meters
                                    ? 'At the office'
                                    : `${Math.round(distance)} m from the office`}
                            </span>
                        )}
                    </p>
                )}

                {location.state === 'idle' && geofence && (
                    <p className="flex items-center gap-1.5">
                        <MapPin aria-hidden="true" className="size-3.5" />
                        {geofence.enforced
                            ? `Clocking is only allowed within ${geofence.radius_meters} m of the office.`
                            : `Shifts are tagged as in or out of the ${geofence.radius_meters} m office area.`}
                    </p>
                )}

                <InputError message={error} />
            </div>
        </section>
    );
}
