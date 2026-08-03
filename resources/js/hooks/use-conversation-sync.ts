import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useCallback, useEffect, useRef } from 'react';
import { useActiveConversationId } from '@/hooks/use-active-channel';
import { useNotificationSound } from '@/hooks/use-notification-sound';
import {
    useCurrentWorkspace,
    useSidebarConversations,
} from '@/hooks/use-workspace';
import { channels, events } from '@/lib/echo';
import { pageIsUnattended, showNotification } from '@/lib/notifications';
import { show as showConversation } from '@/routes/conversations';
import type { ConversationSignalEvent } from '@/types';

/** Collapse a burst of arrivals into one refresh. */
const REFRESH_DELAY_MS = 300;

/**
 * Keeps the sidebar's DM list and unread badges current while the user is
 * elsewhere in the workspace.
 *
 * # Why a reload rather than a client-side merge
 *
 * The sidebar is built from the shared `snack` prop, which is server-owned — the
 * DM list, its ordering, and the unread counts all come from queries that know
 * things the client does not, starting with how a conversation should be titled
 * for *this* viewer. Merging an incoming event into that payload by hand would
 * mean reimplementing those rules in the browser and keeping the two in step
 * forever. Re-reading one prop is a single cheap request and leaves one source of
 * truth.
 *
 * The events themselves carry no message content, so nothing is rendered from
 * them directly; they are purely a signal that the payload is now stale.
 *
 * # Why it is debounced
 *
 * A lively group produces a burst of arrivals, and one reload per message would
 * turn somebody else's typing into a request storm. Trailing edge, so the
 * refresh that lands is the one that reflects the whole burst.
 */
export function useConversationSync(userId: number) {
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const notify = useConversationActivityNotifier();

    const refresh = useCallback(() => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            timer.current = null;

            router.reload({
                // Only the navigation payload. Reloading the page's own props
                // would replace the conversation a reader is looking at.
                only: ['snack'],
            });
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

    /*
     * The user's own private channel: the one subscription that exists for as
     * long as the tab is open, regardless of which page they are on. The
     * conversation's presence channel cannot serve this — by definition these
     * people are not in it.
     *
     * `userId` is only used to name the channel. The server authorizes the
     * subscription against the session, so a tampered id subscribes to nothing.
     */
    useEcho<ConversationSignalEvent>(
        channels.user(userId),
        [events.conversationStarted, events.conversationRead],
        refresh,
        [userId, refresh],
    );

    useEcho<ConversationSignalEvent>(
        channels.user(userId),
        [events.conversationActivity],
        (payload) => {
            notify(payload.conversation_id);
            refresh();
        },
        [userId, refresh, notify],
    );
}

/**
 * Raises a desktop notification for a DM the reader does not have open.
 *
 * # Why this is separate from the timeline's own alert
 *
 * {@see useMessageAlert} only fires for the container on screen — it listens on
 * that conversation's presence channel, which by definition nobody else's
 * conversation reaches. Without this, going to make coffee with a channel open
 * would mean missing every direct message that arrived.
 *
 * # Why there is no message preview here
 *
 * `ConversationActivity` carries a conversation id and nothing else, on purpose:
 * one broadcast fans out to every participant, so anything in it is something
 * the server has not tailored to a particular reader. The banner says who is
 * asking for attention and stops there, which is the same trade the unread
 * badge already makes.
 */
function useConversationActivityNotifier() {
    const current = useCurrentWorkspace();
    const { conversations, muted } = useSidebarConversations();
    const activeConversationId = useActiveConversationId();
    const playArrival = useNotificationSound();

    /*
     * `useEcho` captures its callback against a fixed dependency list, so
     * everything read at notification time goes through a ref. Reading these
     * directly would pin the handler to the conversation list as it stood when
     * the tab opened.
     */
    const latest = useRef({
        conversations,
        muted,
        activeConversationId,
        workspaceSlug: current?.workspace.slug ?? null,
    });

    // Assigned in an effect rather than during render: effects flush long
    // before a broadcast can arrive, and the initial value is already correct.
    useEffect(() => {
        latest.current = {
            conversations,
            muted,
            activeConversationId,
            workspaceSlug: current?.workspace.slug ?? null,
        };
    });

    return useCallback(
        (conversationId: string) => {
            const state = latest.current;
            // Destructured so the null check below still holds inside `onClick`;
            // narrowing on a property does not survive into a nested closure.
            const { workspaceSlug } = state;

            /*
             * The conversation on screen is handled by its own timeline, which has
             * the message itself and can say more than this can. Both events reach
             * a participant who is looking at it, so without this the reader gets
             * two alerts for one message.
             */
            if (
                conversationId === state.activeConversationId ||
                workspaceSlug === null
            ) {
                return;
            }

            // Muted conversations are still in the payload — they are rendered in
            // their own collapsed section — so being absent from the unmuted list
            // is not enough to conclude anything.
            if (state.muted.some((one) => one.id === conversationId)) {
                return;
            }

            const conversation = state.conversations.find(
                (one) => one.id === conversationId,
            );

            // A conversation the sidebar has not seen yet: the first message of a
            // brand new DM arrives before the payload naming it does. The refresh
            // that follows will bring the title, but the notification is worth
            // more now than a better-worded one would be later.
            const title =
                conversation === undefined
                    ? 'New message'
                    : conversation.display_name;

            if (conversation?.notification_level === 'nothing') {
                return;
            }

            /*
             * The sound plays whether or not the reader is at their desk — a DM
             * landing in a conversation they cannot see is exactly the case a sound
             * is for, and gating it on the page being unattended (as the banner
             * below still is) meant the only alert for an off-screen direct message
             * was one the reader had to already be away to receive.
             */
            playArrival();

            // The banner stays gated: an OS notification for something a glance at
            // the sidebar would show is how people learn to switch them off.
            if (!pageIsUnattended()) {
                return;
            }

            showNotification({
                title,
                body: 'Sent you a message',
                tag: `conversation:${conversationId}`,
                onClick: () =>
                    router.visit(
                        showConversation.url({
                            workspace: workspaceSlug,
                            conversation: conversationId,
                        }),
                    ),
            });
        },
        [playArrival],
    );
}
