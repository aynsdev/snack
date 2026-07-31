import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AttachmentList } from '@/components/snack/attachment-list';
import type { Attachment } from '@/types';

function makeAttachment(overrides: Partial<Attachment> = {}): Attachment {
    return {
        id: 1,
        name: 'budget.pdf',
        mime_type: 'application/pdf',
        size: 2048,
        human_size: '2 KB',
        kind: 'file',
        width: null,
        height: null,
        download_url: '/files/1?signature=abc',
        thumbnail_url: null,
        preview_url: null,
        created_at: '2026-07-31T09:00:00+00:00',
        ...overrides,
    };
}

/**
 * How files render under a message.
 *
 * The rule being asserted is that images and everything else are two different
 * things to a reader — a screenshot *is* the message, a spreadsheet is a thing
 * to open later — so they get two different treatments.
 */
describe('AttachmentList', () => {
    it('renders nothing when there are no files', () => {
        const { container } = render(<AttachmentList attachments={[]} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders a non-image as a download chip', () => {
        render(<AttachmentList attachments={[makeAttachment()]} />);

        const link = screen.getByRole('link', { name: /budget\.pdf/ });

        expect(link).toHaveAttribute('href', '/files/1?signature=abc');
        expect(screen.getByText('2 KB')).toBeInTheDocument();
    });

    it('previews an image rather than listing its name', () => {
        render(
            <AttachmentList
                attachments={[
                    makeAttachment({
                        name: 'shot.png',
                        kind: 'image',
                        mime_type: 'image/png',
                        preview_url: '/files/1?inline=1&signature=abc',
                        thumbnail_url: '/files/1/thumbnail?signature=abc',
                        width: 800,
                        height: 600,
                    }),
                ]}
            />,
        );

        const image = screen.getByRole('img', { name: 'shot.png' });

        // The generated thumbnail, not the original.
        expect(image).toHaveAttribute(
            'src',
            '/files/1/thumbnail?signature=abc',
        );

        // The stored dimensions reserve the space, so the conversation does not
        // jump when the bytes land.
        expect(image).toHaveAttribute('width', '800');
        expect(image).toHaveAttribute('height', '600');
    });

    it('falls back to the original while the thumbnail is still being generated', () => {
        render(
            <AttachmentList
                attachments={[
                    makeAttachment({
                        name: 'fresh.png',
                        kind: 'image',
                        mime_type: 'image/png',
                        preview_url: '/files/1?inline=1&signature=abc',
                        thumbnail_url: null,
                    }),
                ]}
            />,
        );

        // A picture that has only just been sent has no thumbnail yet; the
        // sender must still see it immediately.
        expect(screen.getByRole('img', { name: 'fresh.png' })).toHaveAttribute(
            'src',
            '/files/1?inline=1&signature=abc',
        );
    });

    it('shows an image with no inline preview as a chip instead', () => {
        render(
            <AttachmentList
                attachments={[
                    makeAttachment({
                        name: 'enormous.png',
                        kind: 'image',
                        mime_type: 'image/png',
                        // Above the inline-preview ceiling, so the server sent
                        // no preview url: a 40 MB PNG is a download, not a
                        // preview that starts on its own.
                        preview_url: null,
                        human_size: '41.2 MB',
                    }),
                ]}
            />,
        );

        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /enormous\.png/ }),
        ).toBeInTheDocument();
    });

    it('renders images and other files side by side', () => {
        render(
            <AttachmentList
                attachments={[
                    makeAttachment({
                        id: 1,
                        name: 'shot.png',
                        kind: 'image',
                        mime_type: 'image/png',
                        preview_url: '/files/1?inline=1',
                        thumbnail_url: '/files/1/thumbnail',
                    }),
                    makeAttachment({ id: 2, name: 'notes.txt' }),
                ]}
            />,
        );

        expect(
            screen.getByRole('img', { name: 'shot.png' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /notes\.txt/ }),
        ).toBeInTheDocument();
    });
});
