import { useChannelSync } from '@/hooks/use-channel-sync';

/**
 * Subscribes the workspace shell to the signed-in user's own event channel so
 * channel badges and arrival sounds keep working while they read something else.
 *
 * Renders nothing, and is a component rather than a bare hook call in the layout
 * for the same two reasons as {@see ConversationSync}: the layout would have to
 * call the hook before it knows whether there is a user at all, and the sidebar
 * is mounted twice on mobile, which would double every refresh.
 */
export function ChannelSync({ userId }: { userId: number }) {
    useChannelSync(userId);

    return null;
}
