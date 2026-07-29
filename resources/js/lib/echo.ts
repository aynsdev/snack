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
    conversation: (conversationId: number) => `conversation.${conversationId}`,
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
} as const;
