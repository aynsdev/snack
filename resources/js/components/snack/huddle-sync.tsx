import { useHuddleSync } from '@/hooks/use-huddle-sync';

/**
 * Subscribes the workspace shell to the signed-in user's own event channel so a
 * call starting anywhere they are a member reaches them, wherever they are.
 *
 * Renders nothing, and is a component rather than a bare hook call in the layout
 * for the same two reasons as {@see ChannelSync}: the layout would have to call
 * the hook before it knows whether there is a user at all, and the sidebar is
 * mounted twice on mobile, which would double every alert.
 *
 * Mounted *inside* {@see HuddleProvider}, unlike its two siblings — the toast it
 * raises carries a Join button, and joining needs the provider that owns the
 * connection.
 */
export function HuddleSync({ userId }: { userId: number }) {
    useHuddleSync(userId);

    return null;
}
