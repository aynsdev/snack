import { useEffect } from 'react';
import {
    useActiveConversationId,
    useIsAssetsView,
    useIsBiteView,
    useIsDirectMessagesView,
    useIsHrView,
    useIsProjectsView,
} from '@/hooks/use-active-channel';

/**
 * Which shape the sidebar takes: the workspace's contents, the DM list, the
 * project list, or the asset library's folder tree.
 */
export type SidebarSection =
    'workspace' | 'direct-messages' | 'projects' | 'assets' | 'hr' | 'bite';

const STORAGE_KEY = 'snack:sidebar-section';

/**
 * Which section of the workspace the reader is in.
 *
 * # Why this is not read off the URL
 *
 * Every other "where am I" answer in the shell is derived from the URL, and for
 * a conversation that does not work: `/w/acme/dms/{id}` is the same URL whether
 * the reader opened it from the DM list or from the roster at the bottom of the
 * channel sidebar. Deriving the section from it meant a click on a name in the
 * workspace sidebar replaced that whole sidebar — channels, Threads, Huddles —
 * with the DM list, so the column the reader had just been reading rearranged
 * itself underneath them and the way back was gone.
 *
 * So the section is where you *entered* from, remembered across the navigation:
 * a conversation keeps the section it was opened in, and only the two entrances
 * — the rail's DMs item, and anything outside DMs — set it. Opening a DM from
 * the channel sidebar leaves that sidebar exactly as it was, with the person's
 * row lit inside it.
 *
 * Kept in `sessionStorage` rather than component state so a reload of the
 * conversation lands back in the section it was opened from, and per-tab because
 * two tabs are genuinely in two different places.
 */
export function useSidebarSection(): SidebarSection {
    const conversationId = useActiveConversationId();
    const isDirectMessagesView = useIsDirectMessagesView();
    const isProjectsView = useIsProjectsView();
    const isAssetsView = useIsAssetsView();
    const isHrView = useIsHrView();
    const isBiteView = useIsBiteView();

    /*
     * A conversation inherits; everywhere else states. With no conversation in
     * the URL, `isDirectMessagesView` is true only on the DM home itself, which
     * is the rail's own destination and so the one page that means "I am in
     * direct messages".
     *
     * Projects and the asset library need none of that machinery:
     * `/projects/pwa-board` is that project and nothing else, and
     * `/assets/folders/12` is that shelf. The URL alone settles both. The
     * ambiguity this hook exists for is specific to conversations, which are
     * reachable from two sections and look identical from both.
     */
    const section: SidebarSection =
        conversationId !== null
            ? recall()
            : isProjectsView
              ? 'projects'
              : isAssetsView
                ? 'assets'
                : isHrView
                  ? 'hr'
                  : isBiteView
                    ? 'bite'
                    : isDirectMessagesView
                      ? 'direct-messages'
                      : 'workspace';

    useEffect(() => {
        /*
         * Only the two sections a conversation can be opened from are worth
         * remembering. Storing 'projects' or 'assets' would mean a DM opened
         * later, from a link, inheriting a section whose sidebar has no
         * conversations in it at all — and the reader would land beside a list
         * they cannot get back to the conversation from.
         */
        if (
            conversationId === null &&
            section !== 'projects' &&
            section !== 'assets' &&
            section !== 'hr' &&
            section !== 'bite'
        ) {
            remember(section);
        }
    }, [conversationId, section]);

    return section;
}

/**
 * Declare a section on the way into it, for the one entrance whose URL cannot
 * say so itself.
 *
 * `GET /w/{workspace}/dms` redirects into the most recently active conversation
 * rather than rendering a list — see ConversationController::index — so the
 * rail's DMs item lands on `/dms/{id}`, which is exactly the URL that inherits.
 * Without this the section would be unreachable: every way into a conversation
 * would keep the workspace sidebar, including the one that means "take me to my
 * direct messages".
 *
 * Called from a click handler, before the visit, so the page renders once and in
 * the right shape.
 */
export function enterSection(section: SidebarSection): void {
    remember(section);
}

/**
 * The last section the reader deliberately entered, or the workspace when there
 * is nothing to go on — a link opened cold into a conversation belongs beside
 * the channel list, which is the shape that can reach everywhere else.
 */
function recall(): SidebarSection {
    if (typeof window === 'undefined') {
        return 'workspace';
    }

    try {
        const stored = window.sessionStorage.getItem(STORAGE_KEY);

        return stored === 'direct-messages' ? stored : 'workspace';
    } catch {
        return fallback;
    }
}

function remember(section: SidebarSection): void {
    if (typeof window === 'undefined') {
        return;
    }

    fallback = section;

    try {
        window.sessionStorage.setItem(STORAGE_KEY, section);
    } catch {
        // Storage can be denied outright — Safari's private mode used to throw
        // on write. The in-memory copy above still carries the section for as
        // long as the tab lives, which is all this needs to survive.
    }
}

/** Only ever read when `sessionStorage` itself is unavailable. */
let fallback: SidebarSection = 'workspace';
