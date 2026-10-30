import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SnackUser } from '@/types';

const api = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
    api,
    ApiError: class ApiError extends Error {},
}));

const { AGENT_MENTION, useMentionCandidates } =
    await import('@/components/snack/mention-picker');

function person(id: number, name: string, username: string): SnackUser {
    return {
        id,
        name,
        username,
        initials: name[0],
        avatar_url: null,
        title: null,
        department: null,
        presence_status: 'online',
        status_emoji: null,
        status_message: null,
    };
}

const roster = [person(2, 'Bo Peep', 'bo'), person(3, 'Cy Young', 'cy')];

beforeEach(() => {
    vi.clearAllMocks();
    api.get.mockResolvedValue({ people: roster });
});

describe('useMentionCandidates', () => {
    it('filters a local roster without any request', async () => {
        const { result } = renderHook(() =>
            useMentionCandidates('bo', { kind: 'local', people: roster }),
        );

        expect(result.current.map((p) => p.username)).toEqual(['bo']);
        expect(api.get).not.toHaveBeenCalled();
    });

    it('offers nobody when the caret is not in a handle', () => {
        const { result } = renderHook(() =>
            useMentionCandidates(null, { kind: 'local', people: roster }),
        );

        expect(result.current).toEqual([]);
        expect(api.get).not.toHaveBeenCalled();
    });

    it('looks a channel roster up once per term', async () => {
        const source = {
            kind: 'remote' as const,
            url: (term: string) => `/mentionable?q=${term}`,
        };

        const { result } = renderHook(() => useMentionCandidates('bo', source));

        await waitFor(() => expect(result.current).toHaveLength(2));

        expect(api.get).toHaveBeenCalledTimes(1);
    });

    /**
     * The regression this file exists for.
     *
     * Both pages build `mentionSource` inline, so the object has a fresh
     * identity on every render. An effect keyed on that object re-runs each
     * time — and since resolving the lookup sets state, which causes a render,
     * which rebuilds the object, the picker fetched in a loop for as long as it
     * was open. Nothing failed; it just hammered the endpoint forever.
     */
    it('does not re-fetch when the caller rebuilds the source object', async () => {
        const { result, rerender } = renderHook(
            ({ term }: { term: string }) =>
                useMentionCandidates(term, {
                    kind: 'remote',
                    // Rebuilt every render, exactly as the pages do it.
                    url: (value: string) => `/mentionable?q=${value}`,
                }),
            { initialProps: { term: 'bo' } },
        );

        await waitFor(() => expect(result.current).toHaveLength(2));

        // Several renders with the term unchanged.
        act(() => {
            rerender({ term: 'bo' });
            rerender({ term: 'bo' });
            rerender({ term: 'bo' });
        });

        /*
         * Waited out deliberately. Each cycle of the loop costs one debounce
         * interval, so an assertion that runs immediately passes even when the
         * bug is present — the second request has not been sent yet. Several
         * intervals have to elapse for "it fetched once" to mean anything.
         */
        await new Promise((resolve) => setTimeout(resolve, 500));

        expect(api.get).toHaveBeenCalledTimes(1);
    });

    it('re-fetches when the term actually changes', async () => {
        const { result, rerender } = renderHook(
            ({ term }: { term: string }) =>
                useMentionCandidates(term, {
                    kind: 'remote',
                    url: (value: string) => `/mentionable?q=${value}`,
                }),
            { initialProps: { term: 'b' } },
        );

        await waitFor(() => expect(result.current).toHaveLength(2));

        rerender({ term: 'bo' });

        await waitFor(() => expect(api.get).toHaveBeenCalledTimes(2));
    });

    it('offers @agent first in a channel, and only while it still matches', async () => {
        const source = {
            kind: 'remote' as const,
            url: (term: string) => `/mentionable?q=${term}`,
        };

        const { result, rerender } = renderHook(
            ({ term }: { term: string }) => useMentionCandidates(term, source),
            { initialProps: { term: 'ag' } },
        );

        await waitFor(() => expect(result.current[0]).toBe(AGENT_MENTION));

        rerender({ term: 'bo' });

        await waitFor(() =>
            expect(result.current).not.toContain(AGENT_MENTION),
        );
    });

    it('never offers @agent in a conversation', () => {
        const { result } = renderHook(() =>
            useMentionCandidates('', { kind: 'local', people: roster }),
        );

        expect(result.current).not.toContain(AGENT_MENTION);
    });
});
