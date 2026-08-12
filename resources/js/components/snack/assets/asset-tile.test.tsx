import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AssetRow } from '@/components/snack/assets/asset-row';
import { AssetTile } from '@/components/snack/assets/asset-tile';
import type { Attachment, LibraryAsset } from '@/types';

function makeFile(overrides: Partial<Attachment> = {}): Attachment {
    return {
        id: 1,
        name: 'logo-final.svg',
        mime_type: 'image/svg+xml',
        size: 4096,
        human_size: '4 KB',
        kind: 'image',
        width: null,
        height: null,
        is_animated: false,
        download_url: '/files/1?signature=abc',
        thumbnail_url: null,
        preview_url: null,
        created_at: '2026-08-01T09:00:00+00:00',
        ...overrides,
    };
}

function makeAsset(overrides: Partial<LibraryAsset> = {}): LibraryAsset {
    return {
        id: 1,
        folder_id: null,
        name: 'Primary logo',
        description: null,
        file: makeFile(),
        creator: null,
        created_at: '2026-08-01T09:00:00+00:00',
        can_manage: false,
        ...overrides,
    };
}

/**
 * The rule under test is the one the gallery got wrong in production: a null
 * `thumbnail_url` does not mean "this file has no picture". It means the queue
 * has not been round, or — for every SVG ever uploaded — that there is no raster
 * to generate. The original is the picture in both cases, and skipping it turned
 * a library of logos into a grid of grey icons.
 */
describe('asset previews', () => {
    it('prefers the generated thumbnail', () => {
        render(
            <AssetTile
                asset={makeAsset({
                    file: makeFile({
                        thumbnail_url: '/files/1/thumbnail?signature=abc',
                        preview_url: '/files/1?inline=1&signature=abc',
                    }),
                })}
                onOpen={() => {}}
            />,
        );

        expect(
            screen.getByRole('presentation', { hidden: true }),
        ).toHaveAttribute('src', '/files/1/thumbnail?signature=abc');
    });

    it('falls back to the original when there is no thumbnail', () => {
        render(
            <AssetTile
                asset={makeAsset({
                    file: makeFile({
                        thumbnail_url: null,
                        preview_url: '/files/1?inline=1&signature=abc',
                    }),
                })}
                onOpen={() => {}}
            />,
        );

        expect(
            screen.getByRole('presentation', { hidden: true }),
        ).toHaveAttribute('src', '/files/1?inline=1&signature=abc');
    });

    it('shows a kind icon when there is no picture at all', () => {
        const { container } = render(
            <AssetTile
                asset={makeAsset({
                    file: makeFile({
                        kind: 'pdf',
                        mime_type: 'application/pdf',
                        name: 'contract.pdf',
                    }),
                })}
                onOpen={() => {}}
            />,
        );

        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByText(/PDF/)).toBeInTheDocument();
    });

    it('names the shelf a file came from, now that the listing is flat', () => {
        render(
            <AssetRow
                asset={makeAsset({ folder_id: 4 })}
                folderName="Brand"
                onOpen={() => {}}
            />,
        );

        expect(screen.getByText('Brand')).toBeInTheDocument();
    });

    it('says so when the bytes behind an entry are gone', () => {
        render(
            <AssetTile asset={makeAsset({ file: null })} onOpen={() => {}} />,
        );

        expect(screen.getByText('File missing')).toBeInTheDocument();
    });
});
