import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useEffect, useRef } from 'react';
import { useActiveChannelSlug } from '@/hooks/use-active-channel';
import { useNotificationSound } from '@/hooks/use-notification-sound';
import { useCurrentWorkspace } from '@/hooks/use-workspace';
import { channels, events } from '@/lib/echo';
import { pageIsUnattended, showNotification } from '@/lib/notifications';
import { show as showChannel } from '@/routes/channels';
import type { ChannelSignalEvent } from '@/types';

/** Collapse a burst of arrivals into one refresh. */
const REFRESH_DELAY_MS = 300;

/**
 * Keeps channel unread badges current while the reader is somewhere else.
 *
 * The exact counterpart of {@see useConversationSync}, and it exists because
 * channels had nothing like it. A message in #design while you were reading
 * #general reached no subscription this client held — `MessageSent` goes to the
 * channel's own presence channel, and by definition you are not in it — so the
 * badge, the sound, and the banner all waited until you happened to reload.
 * Direct messages had this from the beginning; that asymmetry is the bug.
 *
 * As with conversations, the event is only a signal that the `snack` prop is
 * stale: unread counts come from queries the client cannot reproduce, so it
 * re-reads them rather than incrementing a local copy.
 */
export function useChannelSync(userId: number) {
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const notify = useChannelActivityAlert();

    const refresh = useCallback(() => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            timer.current = null;

            // Only the navigation payload; reloading the page's own props would
            // replace the timeline the reader is looking at.
            router.reload({ only: ['snack'] });
        }, REFRESH_DELAY_MS);
    }, []);

    // A pending refresh must not fire into an unmounted tree.
    useEffect(
        () => () => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    useEcho<ChannelSignalEvent>(
        channels.user(userId),
        [events.channelActivity],
        (payload) => {
            notify(payload.channel_id);
            refresh();
        },
        [userId, refresh, notify],
    );

    /*
     * Being added to a channel by somebody else. A separate subscription rather
     * than another name on the one above, because it deliberately does *not*
     * call `notify`: there is no message to announce, and the alert path would
     * bail anyway — it looks the channel up in the sidebar list, which is the
     * very thing this event says is out of date.
     */
    useEcho<ChannelSignalEvent>(
        channels.user(userId),
        [events.channelMembershipGranted],
        () => refresh(),
        [userId, refresh],
    );
}

/**
 * The sound, and the banner, for a channel the reader does not have open.
 *
 * # Why the sound is here and not only in the timeline
 *
 * {@see useMessageAlert} plays the arrival sound, but only for the container on
 * screen — so the one case where a sound is actually doing something useful,
 * telling you about something you cannot see, was the one case that was silent.
 *
 * # Why muted and "nothing" channels are still counted
 *
 * The refresh above happens regardless: a muted channel's unread count still has
 * to be right, it is just rendered quietly. Only the alert is suppressed.
 */
function useChannelActivityAlert() {
    const current = useCurrentWorkspace();
    /*
     * Every joined channel, muted ones included — `useSidebarChannels` splits
     * them for rendering, but this needs to *find* a channel by id before it can
     * decide whether it is muted, and a channel missing from the list is
     * indistinguishable from one this client has not heard of yet.
     */
    const joined = current?.channels ?? [];
    // The URL carries a slug, not an id, so the comparison happens after lookup.
    const activeChannelSlug = useActiveChannelSlug();
    const playArrival = useNotificationSound();

    /*
     * `useEcho` captures its callback against a fixed dependency list, so
     * everything read at alert time goes through a ref. Reading these directly
     * would pin the handler to the channel list as it stood when the tab opened.
     */
    const latest = useRef({
        joined,
        activeChannelSlug,
        workspaceSlug: current?.workspace.slug ?? null,
    });

    // Assigned in an effect rather than during render: effects flush long before
    // a broadcast can arrive, and the initial value is already correct.
    useEffect(() => {
        latest.current = {
            joined,
            activeChannelSlug,
            workspaceSlug: current?.workspace.slug ?? null,
        };
    });

    return useCallback(
        (channelId: number) => {
            const state = latest.current;
            // Destructured so the null check survives into `onClick` below;
            // narrowing a property does not carry into a nested closure.
            const { workspaceSlug } = state;

            if (workspaceSlug === null) {
                return;
            }

            const channel = state.joined.find((one) => one.id === channelId);

            // A channel this client has not been told about yet — it will
            // arrive with the refresh. Saying nothing is better than naming it
            // wrongly.
            if (channel === undefined) {
                return;
            }

            /*
             * The channel on screen is handled by its own timeline, which has
             * the message itself and can say more than this can. Both signals
             * reach a reader looking at it, so without this they get two alerts
             * for one message.
             */
            if (channel.slug === state.activeChannelSlug) {
                return;
            }

            if (channel.is_muted || channel.notification_level === 'nothing') {
                return;
            }

            playArrival();

            // The banner stays gated on the reader being away. An OS
            // notification for something they could see by glancing at the
            // sidebar is the surest way to get notifications switched off.
            if (!pageIsUnattended()) {
                return;
            }

            showNotification({
                title: `#${channel.name}`,
                body: 'New message',
                tag: `channel:${channelId}`,
                onClick: () =>
                    router.visit(
                        showChannel.url({
                            workspace: workspaceSlug,
                            channel: channel.slug,
                        }),
                    ),
            });
        },
        [playArrival],
    );
}
