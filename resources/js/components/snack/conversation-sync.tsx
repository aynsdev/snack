import { useConversationSync } from '@/hooks/use-conversation-sync';

/**
 * Subscribes the workspace shell to the signed-in user's own event channel so
 * the DM list and its badges stay current while they read something else.
 *
 * Renders nothing. It exists as a component rather than a bare hook call in the
 * layout for two reasons: the layout would have to call the hook before it knows
 * whether there is a user at all, and the sidebar — the other obvious home for
 * it — is mounted twice on mobile, which would double every refresh.
 */
export function ConversationSync({ userId }: { userId: number }) {
    useConversationSync(userId);

    return null;
}
