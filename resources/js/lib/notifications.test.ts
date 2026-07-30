import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    pageIsUnattended,
    readNotificationSupport,
    requestNotificationPermission,
    showNotification,
} from '@/lib/notifications';

/**
 * Installs a fake Notification global.
 *
 * jsdom has no Notification API of its own, which is itself the point: the real
 * browser bug this file guards against is a *present* constructor paired with a
 * pre-denied permission, and only a stand-in can reproduce that.
 */
function stubNotification(
    permission: NotificationPermission,
    constructor?: () => unknown,
) {
    const instances: Array<Record<string, unknown>> = [];

    const Stub = vi.fn(function (this: Record<string, unknown>, title, opts) {
        if (constructor !== undefined) {
            constructor();
        }

        Object.assign(this, { title, ...(opts as object), close: vi.fn() });
        instances.push(this);
    }) as unknown as typeof Notification & { mock: { calls: unknown[][] } };

    Object.defineProperty(Stub, 'permission', { value: permission });
    Object.defineProperty(Stub, 'requestPermission', {
        value: vi.fn().mockResolvedValue(permission),
        writable: true,
    });

    vi.stubGlobal('Notification', Stub);

    return { Stub, instances };
}

function setSecureContext(secure: boolean) {
    Object.defineProperty(window, 'isSecureContext', {
        value: secure,
        configurable: true,
    });
}

afterEach(() => {
    vi.unstubAllGlobals();
    setSecureContext(true);
});

describe('readNotificationSupport', () => {
    it('reports an unsupported browser when there is no Notification API', () => {
        setSecureContext(true);

        expect(readNotificationSupport()).toEqual({
            state: 'unavailable',
            reason: 'unsupported',
        });
    });

    it('distinguishes an insecure origin from a denial', () => {
        /*
         * The exact shape of the real bug: snack.test is served over plain HTTP
         * so Reverb's ws:// works, and there Chrome exposes the constructor but
         * pins permission to "denied" before anyone is asked. Reporting that as
         * a denial would tell the user to go fix a browser setting that cannot
         * fix it.
         */
        stubNotification('denied');
        setSecureContext(false);

        expect(readNotificationSupport()).toEqual({
            state: 'unavailable',
            reason: 'insecure-context',
        });
    });

    it('reports a real denial on a secure origin', () => {
        stubNotification('denied');
        setSecureContext(true);

        expect(readNotificationSupport()).toEqual({
            state: 'unavailable',
            reason: 'denied',
        });
    });

    it('reports granted and prompt states', () => {
        setSecureContext(true);

        stubNotification('granted');
        expect(readNotificationSupport()).toEqual({ state: 'granted' });

        stubNotification('default');
        expect(readNotificationSupport()).toEqual({ state: 'prompt' });
    });
});

describe('requestNotificationPermission', () => {
    it('never asks on an insecure origin', async () => {
        const { Stub } = stubNotification('denied');
        setSecureContext(false);

        const support = await requestNotificationPermission();

        expect(Stub.requestPermission).not.toHaveBeenCalled();
        expect(support).toEqual({
            state: 'unavailable',
            reason: 'insecure-context',
        });
    });

    it('does not re-ask once permission has been decided', async () => {
        const { Stub } = stubNotification('granted');
        setSecureContext(true);

        expect(await requestNotificationPermission()).toEqual({
            state: 'granted',
        });
        expect(Stub.requestPermission).not.toHaveBeenCalled();
    });

    it('asks when the decision is still open', async () => {
        const { Stub } = stubNotification('default');
        setSecureContext(true);

        await requestNotificationPermission();

        expect(Stub.requestPermission).toHaveBeenCalledOnce();
    });
});

describe('showNotification', () => {
    it('shows nothing without permission', () => {
        const { Stub } = stubNotification('default');
        setSecureContext(true);

        expect(showNotification({ title: 'Ada', tag: 'x' })).toBeNull();
        expect(Stub).not.toHaveBeenCalled();
    });

    it('passes the body and tag through', () => {
        const { Stub } = stubNotification('granted');
        setSecureContext(true);

        showNotification({ title: 'Ada', body: 'lunch?', tag: 'conv:1' });

        expect(Stub).toHaveBeenCalledWith(
            'Ada',
            expect.objectContaining({ body: 'lunch?', tag: 'conv:1' }),
        );
    });

    it('truncates a long body rather than handing the OS an essay', () => {
        const { Stub } = stubNotification('granted');
        setSecureContext(true);

        showNotification({ title: 'Ada', body: 'a'.repeat(500), tag: 't' });

        const body = (Stub as unknown as { mock: { calls: unknown[][] } }).mock
            .calls[0][1] as { body: string };

        expect(body.body).toHaveLength(180);
        expect(body.body.endsWith('…')).toBe(true);
    });

    it('survives a constructor that throws', () => {
        // Android Chrome refuses `new Notification`, insisting the call come
        // from a service worker registration. An arriving message is not the
        // moment to surface that.
        stubNotification('granted', () => {
            throw new TypeError('Illegal constructor');
        });
        setSecureContext(true);

        expect(() =>
            showNotification({ title: 'Ada', tag: 't' }),
        ).not.toThrow();
    });

    it('focuses the window and runs onClick when activated', () => {
        const { instances } = stubNotification('granted');
        setSecureContext(true);

        const focus = vi.spyOn(window, 'focus').mockImplementation(() => {});
        const onClick = vi.fn();

        showNotification({ title: 'Ada', tag: 't', onClick });
        (instances[0].onclick as () => void)();

        expect(focus).toHaveBeenCalled();
        expect(onClick).toHaveBeenCalled();
        expect(instances[0].close).toHaveBeenCalled();
    });
});

describe('pageIsUnattended', () => {
    it('is false while the tab is visible and focused', () => {
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
        vi.spyOn(document, 'hasFocus').mockReturnValue(true);

        expect(pageIsUnattended()).toBe(false);
    });

    it('is true for a hidden tab', () => {
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
        vi.spyOn(document, 'hasFocus').mockReturnValue(true);

        expect(pageIsUnattended()).toBe(true);
    });

    it('is true for a visible window that does not have focus', () => {
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
        vi.spyOn(document, 'hasFocus').mockReturnValue(false);

        expect(pageIsUnattended()).toBe(true);
    });
});
