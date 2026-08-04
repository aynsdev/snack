/**
 * Desktop (OS-level) notifications.
 *
 * # Why `'Notification' in window` is not the guard
 *
 * The Notification API is restricted to **secure contexts** — HTTPS, or the
 * literal `localhost`. Snack is deliberately served over plain HTTP at
 * `snack.test` in development so Reverb's `ws://` works, and that origin is not
 * one. The failure there is worse than a missing global:
 *
 *     typeof window.Notification  // "function"  — the feature-detect passes
 *     Notification.permission     // "denied"    — permanently, before asking
 *
 * The constructor is still exposed, so every naive guard sails through, and
 * `requestPermission()` resolves `"denied"` without ever showing a prompt. Read
 * naively that says "this user blocked notifications", which would send them to
 * Chrome's site settings to fix something no setting can fix.
 *
 * So `isSecureContext` is checked *before* `permission` is read, and an insecure
 * origin is reported as its own state rather than as a denial.
 *
 * @see resources/js/lib/nonce.ts for the same class of bug, found the hard way.
 */

/** Why notifications cannot be shown, when they cannot. */
export type NotificationBlocker =
    /** No Notification API at all — an old browser, or SSR. */
    | 'unsupported'
    /** The API exists but the origin is not a secure context. Unfixable here. */
    | 'insecure-context'
    /** The user, or their browser's site settings, said no. */
    | 'denied';

export type NotificationSupport =
    /** Permission is granted; `showNotification` will display something. */
    | { state: 'granted' }
    /** Permission has not been decided; asking is worthwhile. */
    | { state: 'prompt' }
    /** Nothing will be shown, and `reason` says whether that can change. */
    | { state: 'unavailable'; reason: NotificationBlocker };

/**
 * What the browser will currently do with a notification request.
 *
 * Read on demand rather than cached: permission can be changed from the
 * browser's own UI at any time, with no event to subscribe to.
 */
export function readNotificationSupport(): NotificationSupport {
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return { state: 'unavailable', reason: 'unsupported' };
    }

    // Deliberately before `permission` — see the note at the top of this file.
    if (window.isSecureContext === false) {
        return { state: 'unavailable', reason: 'insecure-context' };
    }

    switch (Notification.permission) {
        case 'granted':
            return { state: 'granted' };
        case 'denied':
            return { state: 'unavailable', reason: 'denied' };
        default:
            return { state: 'prompt' };
    }
}

/**
 * Ask the browser for permission. Must be called from a user gesture.
 *
 * Only ever called when the state is `prompt`. A permission request that the
 * user dismisses counts as a denial in Chrome and cannot be asked again from
 * the page, so prompting on load — before there is anything to notify about —
 * spends the one chance the app gets.
 */
export async function requestNotificationPermission(): Promise<NotificationSupport> {
    const support = readNotificationSupport();

    if (support.state !== 'prompt') {
        return support;
    }

    try {
        await Notification.requestPermission();
    } catch {
        // Safari <16 only supports the callback form and rejects the promise.
        return readNotificationSupport();
    }

    return readNotificationSupport();
}

export type DesktopNotification = {
    title: string;
    body?: string;
    /**
     * Groups notifications: a second one with the same tag replaces the first
     * rather than stacking. Keyed per conversation so a busy channel leaves one
     * banner, not forty.
     */
    tag: string;
    /** Run when the notification is activated. */
    onClick?: () => void;
    /**
     * Keep the banner up until it is acted on, instead of letting it auto-hide.
     *
     * For things that are still true a minute later and worth acting on then —
     * a live huddle, not an arrived message. A banner that fades after five
     * seconds is a missed call for anybody who was looking away, which is
     * exactly who the notification was for.
     *
     * Honoured on desktop Chrome and Edge and quietly ignored elsewhere, so it
     * is a preference rather than a guarantee.
     */
    requireInteraction?: boolean;
    /**
     * Let the OS play its own alert sound.
     *
     * Off by default because the in-app arrival sound has already played by the
     * time a banner is raised, and two sounds for one event is worse than none.
     */
    audible?: boolean;
};

/** How much message body to hand the OS; it truncates, but not this politely. */
const BODY_LIMIT = 180;

/**
 * Show a notification, or do nothing if the browser will not allow it.
 *
 * Returns the notification so callers can close it, and `null` when none was
 * shown — never throws. On Android Chrome the constructor throws outright
 * (notifications there must come from a service worker registration), and an
 * arriving message is not the moment to surface a platform limitation.
 */
export function showNotification(
    options: DesktopNotification,
): Notification | null {
    if (readNotificationSupport().state !== 'granted') {
        return null;
    }

    try {
        const notification = new Notification(options.title, {
            body: truncate(options.body),
            tag: options.tag,
            // PNG rather than the SVG favicon: several platforms hand the icon
            // straight to the OS notification centre, which does not rasterise.
            icon: '/favicon.png',
            // Re-using a tag updates the banner silently rather than
            // re-alerting; the arrival sound has already played.
            silent: options.audible !== true,
            requireInteraction: options.requireInteraction === true,
        });

        if (options.onClick !== undefined) {
            const { onClick } = options;

            notification.onclick = () => {
                window.focus();
                notification.close();
                onClick();
            };
        }

        return notification;
    } catch {
        return null;
    }
}

function truncate(body: string | undefined): string | undefined {
    if (body === undefined || body.length <= BODY_LIMIT) {
        return body;
    }

    return `${body.slice(0, BODY_LIMIT - 1).trimEnd()}…`;
}

/**
 * Whether the user is currently looking at the page.
 *
 * Both halves matter: a hidden tab is obviously unattended, but so is a visible
 * window sitting behind the editor. Notifying someone about a message already
 * on their screen is the fastest way to get notifications turned off.
 */
export function pageIsUnattended(): boolean {
    if (typeof document === 'undefined') {
        return false;
    }

    return (
        document.visibilityState !== 'visible' ||
        (typeof document.hasFocus === 'function' && !document.hasFocus())
    );
}
