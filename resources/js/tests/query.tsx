import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

/**
 * A provider wrapper for anything that reads a message timeline.
 *
 * The client is built per call, on purpose: the real one deliberately keeps a
 * container's pages long after the timeline unmounts, and a client shared
 * between tests would carry one test's messages into the next.
 *
 * Retries are off so a test asserting a failed request fails once, immediately,
 * instead of waiting out a backoff it did not ask for.
 */
export function withQueryClient() {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false, staleTime: Infinity },
        },
    });

    return function Wrapper({ children }: { children: ReactNode }) {
        return (
            <QueryClientProvider client={client}>
                {children}
            </QueryClientProvider>
        );
    };
}
