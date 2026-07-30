import { useMemo } from 'react';
import { useMessageTimeline } from '@/hooks/use-message-timeline';
import type { TimelineEndpoints } from '@/hooks/use-message-timeline';
import { channels } from '@/lib/echo';
import {
    index as messageIndex,
    store as messageStore,
} from '@/routes/channels/messages';
import { store as markRead } from '@/routes/channels/read';
import type { Channel, Message, MessagePage, SnackUser } from '@/types';

/**
 * A channel's timeline.
 *
 * All of the behaviour lives in {@see useMessageTimeline}, which channels share
 * with conversations; this only says where a channel reads and writes.
 */
export function useChannelMessages({
    channel,
    workspaceSlug,
    initial,
    currentUser,
    onMessageReceived,
}: {
    channel: Channel;
    workspaceSlug: string;
    initial: MessagePage;
    currentUser: SnackUser | null;
    /** Called for messages from other people, for the notification sound. */
    onMessageReceived?: (message: Message) => void;
}) {
    // Memoized so the timeline's callbacks have a stable dependency; rebuilt
    // inline it would be a new object each render and invalidate every one.
    const endpoints = useMemo<TimelineEndpoints>(() => {
        const args = { workspace: workspaceSlug, channel: channel.slug };

        return {
            broadcastChannel: channels.channel(channel.id),
            history: (cursor) =>
                messageIndex.url(args, { query: { before: cursor } }),
            send: () => messageStore.url(args),

            /*
             * Previewing a public channel you have not joined leaves no
             * membership row to advance, so there is nothing to mark read.
             */
            markRead: channel.is_member ? () => markRead.url(args) : null,
        };
    }, [channel.id, channel.is_member, channel.slug, workspaceSlug]);

    return useMessageTimeline({
        endpoints,
        workspaceSlug,
        initial,
        currentUser,
        onMessageReceived,
    });
}
