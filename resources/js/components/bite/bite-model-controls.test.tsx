import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { BiteModelControls } from '@/components/bite/bite-model-controls';
import type { BiteMediaModel } from '@/types/bite';

const models: BiteMediaModel[] = [
    {
        id: 'mystic',
        label: 'Mystic',
        kind: 'image',
        description: 'Photorealistic',
    },
    {
        id: 'recraft-4',
        label: 'Recraft 4',
        kind: 'image',
        description: 'Design and logos',
    },
    {
        id: 'veo-3.1',
        label: 'Veo 3.1',
        kind: 'video',
        description: 'Video with audio',
    },
];

function Controls() {
    const [value, setValue] = useState<string | null>(null);

    return (
        <BiteModelControls models={models} value={value} onChange={setValue} />
    );
}

describe('Bite model controls', () => {
    it('offers models only in the selected generation mode and returns to chat', () => {
        render(<Controls />);
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Image' }));
        expect(screen.getByRole('button', { name: 'Image' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        fireEvent.click(screen.getByRole('combobox', { name: 'Image model' }));
        expect(
            screen.getByRole('option', { name: /Recraft 4/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: /Veo/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('option', { name: /Recraft 4/ }));
        expect(screen.getByRole('combobox')).toHaveTextContent('Recraft 4');

        fireEvent.click(screen.getByRole('button', { name: 'Video' }));
        expect(
            screen.getByRole('combobox', { name: 'Video model' }),
        ).toHaveTextContent('Veo 3.1');

        fireEvent.click(screen.getByRole('button', { name: 'Chat' }));
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    });

    it('withholds unavailable generation modes', () => {
        render(
            <BiteModelControls models={[]} value={null} onChange={vi.fn()} />,
        );
        expect(
            screen.queryByRole('button', { name: 'Image' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Video' }),
        ).not.toBeInTheDocument();
    });
});
