import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useWorkspace } from '@/hooks/use-workspace';
import { inMethod as clockIn, out as clockOut } from '@/routes/hr/clock';

export type Position = {
    latitude: number;
    longitude: number;
    accuracy: number;
};

export type LocationState =
    | { state: 'idle' }
    | { state: 'locating' }
    | { state: 'located'; position: Position }
    | { state: 'unavailable'; reason: string };

/**
 * Ask the browser where we are. Resolves with an error rather than rejecting,
 * because a refusal is an ordinary answer: the server decides whether a
 * clock-in without a location is acceptable for this workspace.
 */
function locate(): Promise<Position | { error: string }> {
    return new Promise((resolve) => {
        if (!('geolocation' in navigator)) {
            resolve({ error: 'This browser cannot share a location.' });

            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) =>
                resolve({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                }),
            (error) =>
                resolve({
                    error:
                        error.code === error.PERMISSION_DENIED
                            ? 'Location access was denied.'
                            : 'Your location could not be determined.',
                }),
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
        );
    });
}

/**
 * Clocking in or out, geotagged: takes a fresh location at the moment of the
 * click and posts it. Shared by the header clock and the HR time clock card
 * so both record a shift the same way.
 */
export function useClock(isClockedIn: boolean) {
    const workspace = useWorkspace();
    const [location, setLocation] = useState<LocationState>({ state: 'idle' });
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    async function submit(
        note?: string,
        onSuccess?: (message: string | null) => void,
    ) {
        setSubmitting(true);
        setErrors({});
        setLocation({ state: 'locating' });

        const result = await locate();
        const position = 'error' in result ? null : result;

        setLocation(
            position
                ? { state: 'located', position }
                : {
                      state: 'unavailable',
                      reason: 'error' in result ? result.error : '',
                  },
        );

        const route = isClockedIn
            ? clockOut(workspace.slug)
            : clockIn(workspace.slug);
        const trimmed = note?.trim() ?? '';

        router.post(
            route.url,
            {
                latitude: position?.latitude ?? null,
                longitude: position?.longitude ?? null,
                accuracy: position ? Math.round(position.accuracy) : null,
                note: trimmed === '' ? null : trimmed,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (next) => setErrors(next),
                onSuccess: (page) =>
                    onSuccess?.(page.props.flash?.success ?? null),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    const error =
        errors.clock ??
        errors.location ??
        errors.latitude ??
        errors.note ??
        undefined;

    return { submit, submitting, location, error };
}

/**
 * Seconds since `since`, re-rendering every second while `since` is set.
 */
export function useElapsedSeconds(since: string | null): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (since === null) {
            return;
        }

        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [since]);

    return since === null
        ? 0
        : Math.max(0, Math.floor((now - new Date(since).getTime()) / 1000));
}
