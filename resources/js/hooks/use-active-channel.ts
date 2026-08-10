import { useCurrentUrl } from '@/hooks/use-current-url';

/**
 * The channel slug in the current URL, or null when the page is not a channel.
 *
 * Derived from the URL rather than threaded down as a layout prop: the sidebar
 * lives above the page in the component tree, so passing it down would mean
 * every channel page remembering to declare which channel it is — and one that
 * forgot would silently highlight the wrong row.
 */
export function useActiveChannelSlug(): string | null {
    const { currentUrl } = useCurrentUrl();

    // /w/{workspace}/channels/{channel}
    const match = currentUrl.match(/^\/w\/[^/]+\/channels\/([^/]+)/);

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

/**
 * The conversation id in the current URL, or null when the page is not one.
 *
 * Derived from the URL for the same reason as the channel slug above. A
 * conversation is addressed by an opaque public id rather than by a slug — an
 * unnamed DM has no name of its own to slug, since it is titled by whoever is
 * in it.
 */
export function useActiveConversationId(): string | null {
    const { currentUrl } = useCurrentUrl();

    // /w/{workspace}/dms/{conversation}, where the id is an opaque ULID.
    const match = currentUrl.match(/^\/w\/[^/]+\/dms\/([^/?#]+)/);

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

/**
 * Whether the current page is the workspace's Threads view.
 *
 * Derived from the URL for the same reason as the two above.
 */
export function useIsThreadsView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/threads(?:[/?#]|$)/.test(currentUrl);
}

/**
 * Whether the current page is the workspace's Mentions view.
 */
export function useIsMentionsView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/mentions(?:[/?#]|$)/.test(currentUrl);
}

/**
 * Whether the current page is the workspace's notification centre.
 *
 * Deliberately excludes `/notification-preferences`, which is a different page
 * on a URL that shares no path segment with this one — the boundary is the
 * segment end, so the settings screen does not light up the inbox row.
 */
export function useIsNotificationsView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/notifications(?:[/?#]|$)/.test(currentUrl);
}

/**
 * Whether the current page is the workspace's Saved view.
 */
export function useIsSavedView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/saved(?:[/?#]|$)/.test(currentUrl);
}

/**
 * Whether the current page is the workspace's Files browser.
 */
export function useIsFilesView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/files(?:[/?#]|$)/.test(currentUrl);
}

/**
 * Whether the current page belongs to Activity.
 *
 * True on the tabbed page itself *and* on the three standalone views it
 * gathers, because the rail names a section rather than a page: arriving at
 * Threads from the sidebar must not make the section it belongs to go dark.
 */
export function useIsActivityView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/(activity|notifications|mentions|threads)(?:[/?#]|$)/.test(
        currentUrl,
    );
}

/**
 * Whether the current page is anywhere in direct messages — the DM home or a
 * conversation inside it.
 */
export function useIsDirectMessagesView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/dms(?:[/?#]|$)/.test(currentUrl);
}

/**
 * Whether the current page is Home: the workspace root, or a channel in it.
 *
 * Channels count because Home *is* the channel view — the rail's first stop is
 * where conversation happens, and the sidebar beside it is the list of rooms.
 */
export function useIsHomeView(): boolean {
    const { currentUrl } = useCurrentUrl();

    // The workspace root, which only ever redirects into a channel, and the
    // channel views themselves — including "Browse channels".
    return (
        /^\/w\/[^/]+\/?(?:[?#]|$)/.test(currentUrl) ||
        /^\/w\/[^/]+\/channels(?:[/?#]|$)/.test(currentUrl)
    );
}

/**
 * Whether the current page is search.
 *
 * Matches with a query string attached, unlike the others, because search is the
 * one view whose URL always carries one — `/w/acme/search?q=budget` is still the
 * search page and the sidebar has to say so.
 */
export function useIsSearchView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/search(?:[/?#]|$)/.test(currentUrl);
}

/**
 * The project slug in the current URL, or null when the page is not a project.
 *
 * Matches the board, the backlog, and the settings screen, which are three
 * addresses for one project — the sidebar has to keep its row lit across all of
 * them. Derived from the URL for the same reason the channel slug is: the
 * sidebar sits above the page in the tree, so threading it down would mean every
 * project page declaring which project it is, and one that forgot would
 * highlight the wrong row.
 */
export function useActiveProjectSlug(): string | null {
    const { currentUrl } = useCurrentUrl();

    // /w/{workspace}/projects/{project}[/backlog|/settings]
    const match = currentUrl.match(/^\/w\/[^/]+\/projects\/([^/?#]+)/);

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

/**
 * Whether the current page is anywhere in the asset library — the top of it, or
 * a folder inside it.
 */
export function useIsAssetsView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/assets(?:[/?#]|$)/.test(currentUrl);
}

/**
 * The folder id in the current URL, or null at the top of the library.
 *
 * Derived from the URL for the reason every other "where am I" answer is: the
 * sidebar sits above the page in the tree, and threading it down would mean
 * every page declaring which folder it is.
 */
export function useActiveAssetFolderId(): number | null {
    const { currentUrl } = useCurrentUrl();

    // /w/{workspace}/assets/folders/{id}
    const match = currentUrl.match(/^\/w\/[^/]+\/assets\/folders\/(\d+)/);

    return match?.[1] ? Number(match[1]) : null;
}

/**
 * Whether the current page is anywhere in Projects — the list, or a board.
 */
export function useIsProjectsView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/projects(?:[/?#]|$)/.test(currentUrl);
}

/**
 * Whether the current page is the workspace's huddle history.
 */
export function useIsHuddlesView(): boolean {
    const { currentUrl } = useCurrentUrl();

    return /^\/w\/[^/]+\/huddles(?:[/?#]|$)/.test(currentUrl);
}
