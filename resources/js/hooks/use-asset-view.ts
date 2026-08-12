import { useCallback, useSyncExternalStore } from 'react';

export type AssetView = 'grid' | 'list';

const VIEWS: AssetView[] = ['grid', 'list'];

/**
 * Whether the library draws tiles or rows.
 *
 * # Why this is remembered rather than reset each visit
 *
 * Which of the two is right is a property of what the reader keeps in their
 * library, not of the visit: somebody whose library is a brand kit wants the
 * gallery every time, and somebody whose library is four hundred contracts wants
 * the list every time. Re-asking on every page load means one of those two
 * people re-clicks the toggle forever.
 *
 * `localStorage`, for the same reason {@see useCollapsedChannelSections} uses
 * it: this is a standing preference, not a per-tab position. Kept per workspace,
 * because the libraries are per workspace and they are not alike — the same
 * person can reasonably want the gallery at one job and the list at another.
 *
 * # Why this is a store rather than useState
 *
 * The page is server-rendered, and the server has no `localStorage`. A hook that
 * seeded `useState` from storage therefore rendered `list` on the client against
 * a server that had said `grid`, and React threw out the whole hydrated tree and
 * rebuilt it — a hydration mismatch, which is a correctness failure rather than
 * a cosmetic one: everything below the mismatch is remounted.
 *
 * `useSyncExternalStore` is the shape that fixes it, and the same one
 * {@see useColorTheme} uses. `getServerSnapshot` is what React renders *and*
 * hydrates with, so both sides agree on the default; the client snapshot is read
 * immediately afterwards, in the same commit cycle, so the remembered view is
 * what actually gets painted.
 *
 * The cache is keyed by workspace because the shell stays mounted when the rail
 * switches workspaces — without the key, the first workspace's choice would
 * follow the reader to every other one.
 */
export function useAssetView(
    workspaceSlug: string,
): [AssetView, (view: AssetView) => void] {
    const view = useSyncExternalStore(
        subscribe,
        () => snapshot(workspaceSlug),
        () => 'grid' as AssetView,
    );

    const choose = useCallback(
        (next: AssetView) => {
            cache = { slug: workspaceSlug, view: next };
            remember(workspaceSlug, next);
            listeners.forEach((listener) => listener());
        },
        [workspaceSlug],
    );

    return [view, choose];
}

const listeners = new Set<() => void>();

/**
 * The last read, held so `getSnapshot` can be called as often as React likes
 * without touching storage — and so it returns a stable value between writes,
 * which is what the store contract requires.
 */
let cache: { slug: string; view: AssetView } | null = null;

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function snapshot(workspaceSlug: string): AssetView {
    if (cache === null || cache.slug !== workspaceSlug) {
        cache = { slug: workspaceSlug, view: recall(workspaceSlug) };
    }

    return cache.view;
}

function storageKey(workspaceSlug: string): string {
    return `snack:asset-view:${workspaceSlug}`;
}

function recall(workspaceSlug: string): AssetView {
    if (typeof window === 'undefined') {
        return 'grid';
    }

    try {
        const stored = window.localStorage.getItem(storageKey(workspaceSlug));

        // Anything else in the slot is somebody else's data or a half-written
        // value. The gallery is the library's default reading either way.
        return VIEWS.includes(stored as AssetView)
            ? (stored as AssetView)
            : 'grid';
    } catch {
        return 'grid';
    }
}

function remember(workspaceSlug: string, view: AssetView): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(storageKey(workspaceSlug), view);
    } catch {
        // Storage can be denied outright. The cache above still holds for as
        // long as the page lives, which is all the toggle needs to work.
    }
}

/**
 * Drop the cached read. Only tests need this: a real session has one storage,
 * and the cache is invalidated by the workspace key everywhere else.
 */
export function resetAssetViewCache(): void {
    cache = null;
}
