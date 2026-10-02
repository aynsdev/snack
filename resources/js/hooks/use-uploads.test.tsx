import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MAX_FILES_PER_MESSAGE, useUploads } from '@/hooks/use-uploads';
import { ApiError, api } from '@/lib/api';
import type * as ApiModule from '@/lib/api';
import type { Attachment } from '@/types';

/*
 * `ApiError` is kept real so the hook's `instanceof` check is exercised rather
 * than stubbed; only the request functions are replaced.
 */
vi.mock('@/lib/api', async (importOriginal) => {
    const actual = await importOriginal<typeof ApiModule>();

    return {
        ...actual,
        api: { upload: vi.fn(), delete: vi.fn() },
    };
});

function makeAttachment(id: number, name = 'budget.pdf'): Attachment {
    return {
        id,
        name,
        mime_type: 'application/pdf',
        size: 1024,
        human_size: '1 KB',
        kind: 'file',
        width: null,
        height: null,
        is_animated: false,
        download_url: `/files/${id}?signature=x`,
        thumbnail_url: null,
        preview_url: null,
        stream_url: null,
        created_at: '2026-07-31T09:00:00+00:00',
    };
}

function makeFile(name = 'budget.pdf', type = 'application/pdf', size = 1024) {
    const file = new File(['x'], name, { type });

    // jsdom does not let a File report an arbitrary size, and the oversize
    // guard is the one thing that reads it.
    Object.defineProperty(file, 'size', { value: size });

    return file;
}

/**
 * The composer's attachment tray.
 *
 * The subject here is what the tray promises the composer: an id list that only
 * ever names uploads the server has actually taken, and an `isUploading` flag
 * that is true for exactly as long as that is not yet the case. Sending on a
 * stale answer to either would claim ids that do not exist.
 */
describe('useUploads', () => {
    beforeEach(() => {
        vi.mocked(api.upload).mockReset();
        vi.mocked(api.delete).mockReset();
        // Not defined in jsdom, and every image row calls it.
        URL.createObjectURL = vi.fn(() => 'blob:preview');
        URL.revokeObjectURL = vi.fn();
    });

    it('holds no ids until the server has taken the file', async () => {
        let resolveUpload: (value: { attachment: Attachment }) => void = () =>
            undefined;

        vi.mocked(api.upload).mockReturnValue(
            new Promise((resolve) => {
                resolveUpload = resolve;
            }),
        );

        const { result } = renderHook(() =>
            useUploads({ workspaceSlug: 'acme' }),
        );

        act(() => result.current.add([makeFile()]));

        expect(result.current.isUploading).toBe(true);
        expect(result.current.attachmentIds).toEqual([]);

        await act(async () => {
            resolveUpload({ attachment: makeAttachment(7) });
        });

        await waitFor(() => expect(result.current.isUploading).toBe(false));
        expect(result.current.attachmentIds).toEqual([7]);
    });

    it('reports a failed upload without blocking the send', async () => {
        vi.mocked(api.upload).mockRejectedValue(
            new ApiError(422, {
                file: ['That kind of file cannot be shared here.'],
            }),
        );

        const { result } = renderHook(() =>
            useUploads({ workspaceSlug: 'acme' }),
        );

        act(() => result.current.add([makeFile('payload.exe')]));

        await waitFor(() =>
            expect(result.current.uploads[0].error).toBe(
                'That kind of file cannot be shared here.',
            ),
        );

        // A failed row must not read as still in flight, or the composer waits
        // forever for something that is never going to finish.
        expect(result.current.isUploading).toBe(false);
        expect(result.current.attachmentIds).toEqual([]);
    });

    it('rejects an oversize file without spending a request on it', () => {
        const { result } = renderHook(() =>
            useUploads({ workspaceSlug: 'acme' }),
        );

        act(() =>
            result.current.add([
                makeFile('huge.mov', 'video/quicktime', 500 * 1024 * 1024),
            ]),
        );

        expect(result.current.uploads[0].error).not.toBeNull();
        expect(api.upload).not.toHaveBeenCalled();
    });

    it('caps the tray against its current size, not the batch', () => {
        vi.mocked(api.upload).mockReturnValue(new Promise(() => undefined));

        const { result } = renderHook(() =>
            useUploads({ workspaceSlug: 'acme' }),
        );

        act(() =>
            result.current.add(
                Array.from({ length: MAX_FILES_PER_MESSAGE - 1 }, (_, index) =>
                    makeFile(`file-${index}.pdf`),
                ),
            ),
        );

        act(() =>
            result.current.add([makeFile('one.pdf'), makeFile('two.pdf')]),
        );

        expect(result.current.uploads).toHaveLength(MAX_FILES_PER_MESSAGE);
        expect(result.current.isFull).toBe(true);
    });

    it('deletes an upload server-side when the author discards it', async () => {
        vi.mocked(api.upload).mockResolvedValue({
            attachment: makeAttachment(9),
        });
        vi.mocked(api.delete).mockResolvedValue({});

        const { result } = renderHook(() =>
            useUploads({ workspaceSlug: 'acme' }),
        );

        act(() => result.current.add([makeFile()]));

        await waitFor(() => expect(result.current.attachmentIds).toEqual([9]));

        act(() => result.current.remove(result.current.uploads[0].nonce));

        expect(result.current.uploads).toHaveLength(0);
        expect(api.delete).toHaveBeenCalledWith('/w/acme/uploads/9');
    });

    it('does not delete anything when the tray is cleared after a send', async () => {
        vi.mocked(api.upload).mockResolvedValue({
            attachment: makeAttachment(9),
        });

        const { result } = renderHook(() =>
            useUploads({ workspaceSlug: 'acme' }),
        );

        act(() => result.current.add([makeFile()]));

        await waitFor(() => expect(result.current.attachmentIds).toEqual([9]));

        act(() => result.current.clear());

        // Those uploads now belong to the message that just claimed them.
        expect(result.current.uploads).toHaveLength(0);
        expect(api.delete).not.toHaveBeenCalled();
    });

    it('releases the object url of an image it drops', async () => {
        vi.mocked(api.upload).mockResolvedValue({
            attachment: makeAttachment(3, 'shot.png'),
        });
        vi.mocked(api.delete).mockResolvedValue({});

        const { result } = renderHook(() =>
            useUploads({ workspaceSlug: 'acme' }),
        );

        act(() => result.current.add([makeFile('shot.png', 'image/png')]));

        await waitFor(() => expect(result.current.uploads).toHaveLength(1));

        act(() => result.current.remove(result.current.uploads[0].nonce));

        // An unreleased object url pins the whole file in memory.
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview');
    });
});
