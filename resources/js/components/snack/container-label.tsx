import { ChannelIcon } from '@/components/snack/channel-icon';
import { useSidebarConversations, useWorkspace } from '@/hooks/use-workspace';
import { show as showChannel } from '@/routes/channels';
import { show as showConversation } from '@/routes/conversations';
import type { ThreadContainer } from '@/types';

/**
 * Where a message came from — "#design", or the name of a DM.
 *
 * Shared by every view that lists messages out of context (Threads, Mentions,
 * and later Saved and Search), because the awkward part is the same in all of
 * them: a conversation's title is viewer-specific, so the server cannot send
 * one. It is looked up in the sidebar payload the page already holds.
 */
export function useContainerLabel(container: ThreadContainer | null): string {
    const { conversations } = useSidebarConversations();

    if (container === null) {
        return 'Unknown';
    }

    if (container.kind === 'channel') {
        return `#${container.slug}`;
    }

    /*
     * A conversation that has dropped off the sidebar — it is capped, and an old
     * mention can outlive its place in the list — has no name to show. "Direct
     * message" is honest; inventing one from stale data would be worse.
     */
    return (
        conversations.find((conversation) => conversation.id === container.id)
            ?.display_name ?? 'Direct message'
    );
}

/**
 * The link a listed message navigates to.
 *
 * The container, not the message: a message is read where it was said, and
 * landing on one with no surrounding conversation is the disorientation these
 * views exist to avoid.
 */
export function useContainerHref(
    container: ThreadContainer | null,
): string | null {
    const workspace = useWorkspace();

    if (container === null) {
        return null;
    }

    return container.kind === 'channel'
        ? showChannel.url({
              workspace: workspace.slug,
              channel: container.slug,
          })
        : showConversation.url({
              workspace: workspace.slug,
              conversation: container.id,
          });
}

/**
 * The channel icon and name, as one line.
 */
export function ContainerLabel({
    container,
}: {
    container: ThreadContainer | null;
}) {
    const label = useContainerLabel(container);

    return (
        <>
            {container?.kind === 'channel' && (
                <ChannelIcon
                    type={container.type}
                    className="size-3.5 shrink-0 text-muted-foreground"
                />
            )}

            <span className="truncate text-sm font-semibold">{label}</span>
        </>
    );
}
