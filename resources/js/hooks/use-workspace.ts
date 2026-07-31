import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type {
    Channel,
    Conversation,
    CurrentWorkspace,
    SnackUser,
    UnreadState,
    Workspace,
    WorkspaceAbilities,
    WorkspaceSummary,
} from '@/types';

/**
 * The workspace the user is currently inside, or null on pages that are not
 * workspace-scoped (auth, settings, the workspace picker).
 *
 * Reads the shared `snack` prop rather than a client-side store: Inertia
 * already re-sends this on every navigation, so a store would only add a second
 * source of truth to keep in sync.
 */
export function useCurrentWorkspace(): CurrentWorkspace | null {
    return usePage().props.snack.current;
}

/**
 * The current workspace, throwing when there is none.
 *
 * Use inside components that only ever render within a workspace layout — it
 * turns "undefined at runtime, three components deep" into an immediate,
 * obvious failure.
 */
export function useWorkspace(): Workspace {
    const current = useCurrentWorkspace();

    if (current === null) {
        throw new Error(
            'useWorkspace() was called outside a workspace-scoped page. Use useCurrentWorkspace() if the workspace is optional here.',
        );
    }

    return current.workspace;
}

/**
 * What the signed-in user may do here. Drives which controls render; the server
 * re-authorizes every request regardless.
 */
export function useWorkspaceAbilities(): WorkspaceAbilities {
    return useWorkspace().abilities;
}

/** Every workspace the user belongs to, for the rail. */
export function useWorkspaceRail(): WorkspaceSummary[] {
    return usePage().props.snack.workspaces;
}

/** The signed-in user in their workspace-facing shape. */
export function useCurrentUser(): SnackUser | null {
    return usePage().props.auth.profile;
}

/**
 * The user's joined channels, split into the groupings the sidebar renders.
 *
 * Sorting happens server-side; this only partitions, so the memo is cheap and
 * exists to keep the array identities stable across re-renders.
 */
export function useSidebarChannels(): {
    channels: Channel[];
    starred: Channel[];
    muted: Channel[];
} {
    const current = useCurrentWorkspace();

    return useMemo(() => {
        const all = current?.channels ?? [];

        return {
            channels: all.filter((channel) => !channel.is_muted),
            // Starring lands with the rest of the personalization work; the
            // grouping exists now so the sidebar's shape is already right.
            starred: [],
            muted: all.filter((channel) => channel.is_muted),
        };
    }, [current?.channels]);
}

export function useUnreadState(): UnreadState {
    const current = useCurrentWorkspace();

    return (
        current?.unread ?? {
            channels: {},
            conversations: {},
            mentions: 0,
            threads: 0,
            notifications: 0,
        }
    );
}

/**
 * The user's conversations, split the way the sidebar renders them.
 *
 * Ordering is decided server-side — most recently active first, with NULLS LAST
 * so a thread that was just started does not outrank a live one — so this only
 * partitions. The memo exists to keep the array identities stable across
 * re-renders.
 */
export function useSidebarConversations(): {
    conversations: Conversation[];
    muted: Conversation[];
} {
    const current = useCurrentWorkspace();

    return useMemo(() => {
        const all = current?.conversations ?? [];

        return {
            conversations: all.filter((conversation) => !conversation.is_muted),
            muted: all.filter((conversation) => conversation.is_muted),
        };
    }, [current?.conversations]);
}
