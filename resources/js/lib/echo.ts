import { configureEcho } from '@laravel/echo-react';

/**
 * Real-time transport.
 *
 * Configured once, at module load, before any component mounts — `useEcho` and
 * friends read from this single instance, so subscriptions are shared and
 * reference-counted rather than opening a socket per component.
 *
 * Only the public app key reaches the browser. Every private and presence
 * subscription is still authorized server-side by routes/channels.php, so a
 * tampered client gains nothing.
 *
 * # The page protocol decides the socket protocol, not this config
 *
 * pusher-js computes `useTLS` as "page is https **or** forceTLS !== false". The
 * page always wins: on an HTTPS page it dials `wss://` and uses `wssPort` no
 * matter what `forceTLS` says here. Setting `forceTLS: false` does not opt out.
 *
 * So an HTTPS-served app needs Reverb reachable over TLS on `wssPort` — locally
 * that means a secured proxy in front of it (`herd proxy ... --secure`), not
 * plain `reverb:start` on 8080. Get this wrong and there is no error to find:
 * the subscription is created, the connection sits at "unavailable", and
 * messages simply never arrive.
 *
 * Both ports come from the same env var because Reverb is one server; whether
 * it is spoken to over ws or wss is decided above, not by a second port.
 */
configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

/**
 * Channel name builders.
 *
 * Every broadcast channel name is produced here so the client and
 * routes/channels.php cannot drift. A typo in a channel string fails silently —
 * the subscription simply never receives anything — which is exactly the kind
 * of bug that is painful to find later.
 */
export const channels = {
    workspace: (workspaceId: number) => `workspace.${workspaceId}`,
    channel: (channelId: number) => `channel.${channelId}`,
    // Named by the conversation's opaque public id, matching the binding in
    // routes/channels.php — the primary key is never sent to the client.
    conversation: (conversationId: string) => `conversation.${conversationId}`,
    thread: (messageId: number) => `thread.${messageId}`,
    user: (userId: number) => `user.${userId}`,
    notifications: (userId: number) => `App.Models.User.${userId}`,
} as const;

/**
 * Broadcast event names, matching each event's `broadcastAs()`.
 *
 * The leading dot tells Echo the name is literal rather than a class path.
 */
export const events = {
    channelCreated: '.channel.created',
    workspaceMemberJoined: '.workspace.member_joined',
    messageSent: '.message.sent',
    messageUpdated: '.message.updated',
    messageDeleted: '.message.deleted',
    threadActivity: '.thread.activity',
    reactionChanged: '.reaction.changed',
    pinChanged: '.pin.changed',
    channelRead: '.channel.read',
    conversationStarted: '.conversation.started',
    conversationActivity: '.conversation.activity',
    conversationRead: '.conversation.read',
    conversationUpdated: '.conversation.updated',
} as const;
