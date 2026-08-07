import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type {
    Channel,
    Conversation,
    CurrentWorkspace,
    Project,
    ProjectGroup,
    SnackUser,
    UnreadState,
    Workspace,
    WorkspaceAbilities,
    WorkspacePerson,
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
    /**
     * Group conversations only. Direct ones are rendered from the people
     * roster instead, so listing them here as well would show the same thread
     * twice under two different names.
     */
    groups: Conversation[];
    /** The muted half of `groups`, for the collapsed Muted section. */
    mutedGroups: Conversation[];
} {
    const current = useCurrentWorkspace();

    return useMemo(() => {
        const all = current?.conversations ?? [];
        const unmuted = all.filter((conversation) => !conversation.is_muted);
        const muted = all.filter((conversation) => conversation.is_muted);
        const isGroup = (conversation: Conversation) =>
            conversation.type !== 'direct';

        return {
            conversations: unmuted,
            muted,
            groups: unmuted.filter(isGroup),
            /*
             * A muted direct message stays on its person's row, wearing the
             * bell icon. Moving it down here would take a colleague out of the
             * roster because of a notification setting, leaving a list of "all
             * the members" with members missing from it.
             */
            mutedGroups: muted.filter(isGroup),
        };
    }, [current?.conversations]);
}

/**
 * The people the DM list renders: everyone else in the workspace, each with
 * their existing one-to-one conversation or null.
 *
 * Ordering is decided server-side — the threads with recent activity first,
 * then the rest of the roster by name — so this only passes it through. Empty
 * for a guest, who has no member directory.
 */
export function useSidebarPeople(): WorkspacePerson[] {
    return useCurrentWorkspace()?.people ?? [];
}

/** One heading in the sidebar's project list; a null group is the leftovers. */
export type ProjectSection = {
    group: ProjectGroup | null;
    projects: Project[];
};

/**
 * The projects the viewer is on, bucketed under the groups they are filed in.
 *
 * Not every project in the workspace: the sidebar lists the boards you work, and
 * the project index lists the ones you could. See
 * App\Services\WorkspaceNavigator::memberProjects().
 *
 * `sections` is what the list renders. A workspace with no groups — or one where
 * none of *your* projects is filed — yields a single section with no heading,
 * which is the flat list the sidebar has always been.
 */
export function useSidebarProjects(): {
    projects: Project[];
    starred: Project[];
    sections: ProjectSection[];
} {
    const current = useCurrentWorkspace();

    return useMemo(() => {
        const projects = current?.projects ?? [];
        const groups = current?.project_groups ?? [];

        /*
         * Starred projects are lifted out of their groups rather than repeated
         * above them. A project in two places in a list of eight rows is two
         * rows saying the same thing, and the reader who starred it has said
         * which of the two they want to look at.
         */
        const starred = projects.filter((project) => project.is_starred);
        const rest = projects.filter((project) => !project.is_starred);

        const sections: ProjectSection[] = groups
            .map((group) => ({
                group,
                projects: rest.filter(
                    (project) => project.project_group_id === group.id,
                ),
            }))
            // A heading whose every project is starred has nothing left under
            // it, and a heading over nothing is a wasted row in the narrowest
            // column in the application.
            .filter((section) => section.projects.length > 0);

        const filed = new Set(groups.map((group) => group.id));

        const ungrouped = rest.filter(
            (project) =>
                project.project_group_id === null ||
                !filed.has(project.project_group_id),
        );

        /*
         * The leftovers go last and only when there are any — and they keep the
         * "Ungrouped" heading only when something above them has one. A sidebar
         * whose every project is ungrouped is not a list with one section, it is
         * a list.
         */
        if (ungrouped.length > 0) {
            sections.push({ group: null, projects: ungrouped });
        }

        return { projects, starred, sections };
    }, [current?.projects, current?.project_groups]);
}
