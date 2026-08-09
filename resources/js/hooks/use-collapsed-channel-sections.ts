import { useCallback, useState } from 'react';

/**
 * Which channel sections the reader has folded away.
 *
 * # Why this is remembered, and where
 *
 * Collapsing a section is a statement about a list you look at all day — "I do
 * not need to see these" — so it has to survive a reload the way the theme
 * does. `localStorage` rather than `sessionStorage` for that reason: this is not
 * a per-tab position like {@see useSidebarSection}, it is a standing preference.
 *
 * Kept per workspace, because sections are per workspace: folding "Clients"
 * away at one job should not fold anything at another. Ids rather than names,
 * so renaming a section does not unfold it.
 *
 * The leftovers have no id, so they key on `'none'` — the same shape the ⋯
 * menu's "Other" uses.
 *
 * # Why the state is re-read during render
 *
 * `useState(initialiser)` runs its initialiser once and never again, so a hook
 * seeded from `workspaceSlug` would keep the first workspace's set forever —
 * the shell stays mounted when the rail switches workspaces. The reset is done
 * while rendering rather than in an effect: React re-runs the component before
 * committing, so the new workspace's sidebar never paints with the old
 * workspace's folds and then correct itself.
 */
export function useCollapsedChannelSections(workspaceSlug: string): {
    isCollapsed: (groupId: number | null) => boolean;
    toggle: (groupId: number | null) => void;
    expand: (groupId: number | null) => void;
} {
    const [collapsed, setCollapsed] = useState<Set<string>>(() =>
        recall(workspaceSlug),
    );
    const [loadedFor, setLoadedFor] = useState(workspaceSlug);

    if (loadedFor !== workspaceSlug) {
        setLoadedFor(workspaceSlug);
        setCollapsed(recall(workspaceSlug));
    }

    const write = useCallback(
        (next: Set<string>) => {
            setCollapsed(next);
            remember(workspaceSlug, next);
        },
        [workspaceSlug],
    );

    return {
        isCollapsed: useCallback(
            (groupId: number | null) => collapsed.has(keyFor(groupId)),
            [collapsed],
        ),

        toggle: useCallback(
            (groupId: number | null) => {
                const key = keyFor(groupId);
                const next = new Set(collapsed);

                if (!next.delete(key)) {
                    next.add(key);
                }

                write(next);
            },
            [collapsed, write],
        ),

        /**
         * Unfold one section, whatever state it was in — what a channel dropped
         * into a folded section needs, so the reader sees where it landed.
         */
        expand: useCallback(
            (groupId: number | null) => {
                const key = keyFor(groupId);

                if (!collapsed.has(key)) {
                    return;
                }

                const next = new Set(collapsed);
                next.delete(key);
                write(next);
            },
            [collapsed, write],
        ),
    };
}

function keyFor(groupId: number | null): string {
    return groupId === null ? 'none' : String(groupId);
}

function storageKey(workspaceSlug: string): string {
    return `snack:collapsed-channel-sections:${workspaceSlug}`;
}

function recall(workspaceSlug: string): Set<string> {
    if (typeof window === 'undefined') {
        return new Set();
    }

    try {
        const stored = window.localStorage.getItem(storageKey(workspaceSlug));
        const parsed: unknown = stored === null ? [] : JSON.parse(stored);

        // Anything else in the slot is somebody else's data or a half-written
        // value; an unfolded sidebar is the safe reading of it either way.
        return Array.isArray(parsed)
            ? new Set(
                  parsed.filter((id): id is string => typeof id === 'string'),
              )
            : new Set();
    } catch {
        return new Set();
    }
}

function remember(workspaceSlug: string, collapsed: Set<string>): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(
            storageKey(workspaceSlug),
            JSON.stringify([...collapsed]),
        );
    } catch {
        // Storage can be denied outright. The state above still holds for as
        // long as the page lives, which is all the folding needs to work.
    }
}
