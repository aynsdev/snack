import { useMemo } from 'react';
import { useMessageTimeline } from '@/hooks/use-message-timeline';
import type { TimelineEndpoints } from '@/hooks/use-message-timeline';
import { channels } from '@/lib/echo';
import {
    index as messageIndex,
    store as messageStore,
} from '@/routes/conversations/messages';
import { store as markRead } from '@/routes/conversations/read';
import type { Conversation, Message, MessagePage, SnackUser } from '@/types';

/**
 * A conversation's timeline.
 *
 * All of the behaviour lives in {@see useMessageTimeline}, which conversations
 * share with channels; this only says where a conversation reads and writes.
 * Keeping them on one implementation is what stops a DM from quietly acquiring
 * its own ordering or de-duplication rules.
 */
export function useConversationMessages({
    conversation,
    workspaceSlug,
    initial,
    currentUser,
    onMessageReceived,
}: {
    conversation: Conversation;
    workspaceSlug: string;
    initial: MessagePage;
    currentUser: SnackUser | null;
    /** Called for messages from other people, for the notification sound. */
    onMessageReceived?: (message: Message) => void;
}) {
    // Memoized so the timeline's callbacks have a stable dependency; rebuilt
    // inline it would be a new object each render and invalidate every one.
    const endpoints = useMemo<TimelineEndpoints>(() => {
        const args = {
            workspace: workspaceSlug,
            conversation: conversation.id,
        };

        return {
            broadcastChannel: channels.conversation(conversation.id),
            history: (cursor) =>
                messageIndex.url(args, { query: { before: cursor } }),
            send: () => messageStore.url(args),

            /*
             * Someone who left a group still reads its history, but advancing a
             * pointer they will never be badged against is pointless work.
             */
            markRead: conversation.has_left ? null : () => markRead.url(args),
        };
    }, [conversation.has_left, conversation.id, workspaceSlug]);

    return useMessageTimeline({
        endpoints,
        workspaceSlug,
        initial,
        currentUser,
        onMessageReceived,
    });
}
