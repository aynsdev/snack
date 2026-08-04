import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { HuddleTarget } from '@/hooks/use-huddle';
import { useHuddle } from '@/hooks/use-huddle';
import { useNotificationSound } from '@/hooks/use-notification-sound';
import { useCurrentWorkspace } from '@/hooks/use-workspace';
import { channels, events } from '@/lib/echo';
import { pageIsUnattended, showNotification } from '@/lib/notifications';
import { show as showChannel } from '@/routes/channels';
import { show as showConversation } from '@/routes/conversations';
import type {
    Channel,
    Conversation,
    HuddleActivityEvent,
    HuddleContainerRef,
} from '@/types';

/**
 * Tells you a call has started somewhere you are a member.
 *
 * # Why a huddle needs its own notifier
 *
 * {@see useChannelSync} and {@see useConversationSync} both raise a banner only
 * when the page is unattended, on the grounds that an OS notification for
 * something a glance at the sidebar would show is how people learn to switch
 * notifications off. A call inverts that reasoning. It is happening *now*, it
 * stops happening, and the cost of missing it is not "read it later" but "the
 * conversation went ahead without you". So this alerts in both directions:
 *
 * - **Attended tab** — an in-app toast carrying a Join button, right where the
 *   user is already looking. This is the case the other two notifiers have no
 *   answer for at all, because for a message the sidebar badge is answer enough.
 * - **Unattended tab** — an OS banner that stays up until it is acted on, since
 *   one that fades after five seconds is a missed call for exactly the person it
 *   was raised for.
 *
 * The sound plays either way. It is the only signal that reaches somebody
 * looking at another application with this tab still technically visible.
 *
 * # Why it withdraws
 *
 * A huddle *ending* is broadcast too, and both surfaces come down when it
 * arrives. An invitation to join a call that finished ten minutes ago is worse
 * than no invitation: it is a promise the app cannot keep, and whoever acts on
 * it finds an empty room and no explanation.
 */
export function useHuddleSync(userId: number) {
    const announce = useHuddleAnnouncer();

    useEcho<HuddleActivityEvent>(
        channels.user(userId),
        [events.huddleActivity],
        announce,
        [userId, announce],
    );
}

/** A live toast and banner, tied to the huddle they are about so both can be taken back. */
type Raised = { toastId: string | number; notification: Notification | null };

/** Everything read at announcement time, kept current in a ref. */
type Announcement = {
    channels: Channel[];
    conversations: Conversation[];
    workspaceSlug: string | null;
    activeHuddleContainer: HuddleContainerRef | null;
    join: (target: HuddleTarget) => Promise<void>;
};

function useHuddleAnnouncer() {
    const current = useCurrentWorkspace();
    const playArrival = useNotificationSound();
    const { join, active } = useHuddle();

    /** What has been raised, by huddle id, so an ending can take it down. */
    const raised = useRef(new Map<string, Raised>());

    /*
     * `useEcho` captures its callback against a fixed dependency list, so
     * everything read at announcement time goes through a ref. Reading these
     * directly would pin the handler to the sidebar as it stood when the tab
     * opened, and every channel joined since would be unknown to it.
     */
    const latest = useRef<Announcement>({
        channels: current?.channels ?? [],
        conversations: current?.conversations ?? [],
        workspaceSlug: current?.workspace.slug ?? null,
        activeHuddleContainer: active?.container ?? null,
        join,
    });

    // Assigned in an effect rather than during render: effects flush long before
    // a broadcast can arrive, and the initial value is already correct.
    useEffect(() => {
        latest.current = {
            channels: current?.channels ?? [],
            conversations: current?.conversations ?? [],
            workspaceSlug: current?.workspace.slug ?? null,
            activeHuddleContainer: active?.container ?? null,
            join,
        };
    });

    // Nothing may outlive the tree: a toast surviving a sign-out would offer to
    // join a call as a user who is no longer signed in.
    useEffect(() => {
        const open = raised.current;

        return () => {
            open.forEach(withdraw);
            open.clear();
        };
    }, []);

    return useCallback(
        (payload: HuddleActivityEvent) => {
            const open = raised.current;

            if (!payload.is_live) {
                const existing = open.get(payload.huddle_id);

                if (existing !== undefined) {
                    withdraw(existing);
                    open.delete(payload.huddle_id);
                }

                return;
            }

            // Already announced. A reconnect replays what the socket missed, and
            // a second banner for one call reads as a second call.
            if (open.has(payload.huddle_id)) {
                return;
            }

            const state = latest.current;

            /*
             * Already on this call — which is what your *other* tab sees when
             * you join from one of them, since the server only leaves out the
             * person who started it. Alerting there rings the phone you are
             * already holding to your ear.
             */
            if (sameContainer(state.activeHuddleContainer, payload.container)) {
                return;
            }

            const place = describe(payload.container, state);

            // Muted, silenced, or a container this client has not been told
            // about yet. Saying nothing beats naming it wrongly.
            if (place === null) {
                return;
            }

            playArrival();

            /*
             * The toast is the notification for an attended tab, and it carries
             * the action rather than pointing at it: the whole decision is "join
             * or don't", so making the user navigate first adds a step to
             * something that is time-limited by nature.
             *
             * Raised even for the container the reader is already looking at.
             * The huddle bar appears there too, but it is a quiet strip under
             * the header, and quiet is the wrong register for a ringing phone.
             */
            const toastId = toast(place.headline, {
                description: 'Huddle started',
                // Until the call ends or the user decides. A call that outlasts
                // its own invitation is the failure this is here to prevent.
                duration: Infinity,
                action: {
                    label: 'Join',
                    onClick: () => void state.join(place.target),
                },
                cancel: { label: 'Ignore', onClick: () => undefined },
            });

            /*
             * The OS banner is for an unattended tab only — "unattended" already
             * covering a visible window sitting behind the editor, which is the
             * common case for a chat app and the one a visibility check alone
             * gets wrong.
             *
             * It navigates rather than joining. An OS notification is clicked
             * from muscle memory at least as often as from intent, and the cost
             * of getting that wrong here is a live microphone in a room the user
             * did not mean to walk into.
             */
            const notification = pageIsUnattended()
                ? showNotification({
                      title: place.headline,
                      body: 'Huddle started. Click to open.',
                      tag: `huddle:${payload.huddle_id}`,
                      requireInteraction: true,
                      onClick: () => router.visit(place.url),
                  })
                : null;

            open.set(payload.huddle_id, { toastId, notification });
        },
        [playArrival],
    );
}

/** Take both surfaces back for one huddle. */
function withdraw({ toastId, notification }: Raised): void {
    toast.dismiss(toastId);
    notification?.close();
}

function sameContainer(
    a: HuddleContainerRef | null,
    b: HuddleContainerRef,
): boolean {
    return a !== null && a.kind === b.kind && a.id === b.id;
}

/**
 * What to call the place a huddle started, and how to get into it.
 *
 * Null when the container is muted, silenced, or not in this client's sidebar
 * payload yet. All three mean "do not announce", and collapsing them here keeps
 * the caller from having to know which of the two container shapes it holds.
 */
function describe(
    container: HuddleContainerRef,
    state: Announcement,
): { headline: string; url: string; target: HuddleTarget } | null {
    const workspace = state.workspaceSlug;

    if (workspace === null) {
        return null;
    }

    if (container.kind === 'channel') {
        const channel = state.channels.find((one) => one.id === container.id);

        if (
            channel === undefined ||
            channel.is_muted ||
            channel.notification_level === 'nothing'
        ) {
            return null;
        }

        return {
            headline: `#${channel.name}`,
            url: showChannel.url({ workspace, channel: channel.slug }),
            target: {
                kind: 'channel',
                workspace,
                channel: channel.slug,
                id: channel.id,
                label: `#${channel.name}`,
            },
        };
    }

    const conversation = state.conversations.find(
        (one) => one.id === container.id,
    );

    if (
        conversation === undefined ||
        conversation.is_muted ||
        conversation.notification_level === 'nothing'
    ) {
        return null;
    }

    return {
        headline: conversation.display_name,
        url: showConversation.url({ workspace, conversation: conversation.id }),
        target: {
            kind: 'conversation',
            workspace,
            conversation: conversation.id,
            id: conversation.id,
            label: conversation.display_name,
        },
    };
}
