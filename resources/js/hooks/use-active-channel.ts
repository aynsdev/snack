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
