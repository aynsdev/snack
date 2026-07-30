import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef } from 'react';
import { useNotificationSound } from '@/hooks/use-notification-sound';
import { pageIsUnattended, showNotification } from '@/lib/notifications';
import type { Message } from '@/types';

export type MessageAlertOptions = {
    /** False when the container is muted or set to notify on nothing. */
    enabled: boolean;
    /**
     * The notification headline for a message from `senderName`.
     *
     * Built by the caller because only it knows the shape that reads correctly:
     * "Ada Lovelace in #general" for a channel, but a bare "Ada Lovelace" for a
     * direct message, where the container *is* the sender.
     */
    title: (senderName: string) => string;
    /** Groups a container's banners so a burst replaces rather than stacks. */
    tag: string;
    /** Where clicking the notification should land. */
    href: string;
};

/**
 * How an arriving message gets the reader's attention.
 *
 * Two channels, deliberately gated differently:
 *
 * - **The sound** plays whenever the container is not muted, including while
 *   the reader is looking straight at it. That is ordinary arrival feedback and
 *   the existing behaviour.
 * - **The desktop notification** is raised only when the page is unattended.
 *   An OS banner for a message already visible on screen is pure noise, and the
 *   surest way to get notifications switched off for good.
 *
 * System messages ("X added Y to the group") alert on neither. They carry no
 * sender, nobody is waiting on them, and a banner for one is an interruption
 * with nothing behind it.
 */
export function useMessageAlert(options: MessageAlertOptions) {
    const playArrival = useNotificationSound(options.enabled);

    /*
     * The timeline subscribes with a fixed dependency list, so the callback it
     * holds is captured once. Reading the options through a ref keeps the
     * returned function stable while still seeing the current container — the
     * alternative is a stale closure that notifies with a previous
     * conversation's title.
     */
    const latest = useRef(options);

    // Assigned in an effect rather than during render: effects flush long
    // before a broadcast can arrive, and the initial value is already correct.
    useEffect(() => {
        latest.current = options;
    });

    return useCallback(
        (message: Message) => {
            const { enabled, title, tag, href } = latest.current;

            if (!enabled || message.sender === null) {
                return;
            }

            playArrival();

            if (!pageIsUnattended()) {
                return;
            }

            showNotification({
                title: title(message.sender.name),
                body: message.body,
                tag,
                onClick: () => router.visit(href),
            });
        },
        [playArrival],
    );
}
