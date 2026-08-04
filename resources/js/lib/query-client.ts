import { QueryClient } from '@tanstack/react-query';

/**
 * The cache behind message timelines.
 *
 * # Why the defaults are what they are
 *
 * A timeline is not kept fresh by polling — it is kept fresh by the WebSocket,
 * which writes arrivals, edits, and deletions straight into this cache. So the
 * usual refetch triggers are all wrong here: refetching on focus or on remount
 * would re-fetch a page the socket has already corrected, and — worse — would
 * race the optimistic state written between the two.
 *
 * `staleTime: Infinity` says exactly that: this data does not go stale by the
 * passage of time, because something else is responsible for keeping it true.
 * Anything that genuinely needs a re-read asks for one by invalidating.
 *
 * `gcTime` is what makes walking back into a channel instant: its pages survive
 * the timeline unmounting, so the reader returns to the history they had
 * scrolled through rather than to the newest page and a spinner.
 */
export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: Infinity,
            gcTime: 30 * 60 * 1000,
            refetchOnWindowFocus: false,
            refetchOnMount: false,
            refetchOnReconnect: false,

            /*
             * One retry, not three. These requests are a page of history behind
             * a reader who is already scrolling; three rounds of backoff is long
             * enough that they conclude it is broken and reload the tab, which
             * costs more than the failed request did.
             */
            retry: 1,
        },
    },
});
