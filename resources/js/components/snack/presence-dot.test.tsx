import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PresenceDot } from '@/components/snack/presence-dot';
import type { PresenceStatus } from '@/types';

describe('PresenceDot', () => {
    it.each<[PresenceStatus, string]>([
        ['online', 'Active'],
        ['away', 'Away'],
        ['offline', 'Offline'],
        ['do_not_disturb', 'Do not disturb'],
    ])('labels %s as "%s"', (status, label) => {
        render(<PresenceDot status={status} />);

        expect(screen.getByText(label)).toBeInTheDocument();
    });

    it('hides the label visually by default', () => {
        const { container } = render(<PresenceDot status="online" />);

        expect(container.querySelector('.sr-only')).toHaveTextContent('Active');
    });

    it('shows a visible label when asked', () => {
        const { container } = render(<PresenceDot status="away" withLabel />);

        expect(container.querySelector('.sr-only')).toBeNull();
        expect(screen.getByText('Away')).toBeInTheDocument();
    });

    it('distinguishes states by shape, not only colour', () => {
        // Roughly one in twelve people cannot reliably separate the green from
        // the amber, so filled/hollow/barred has to carry the meaning too.
        const online = render(<PresenceDot status="online" />).container
            .firstElementChild!.firstElementChild!;
        const away = render(<PresenceDot status="away" />).container
            .firstElementChild!.firstElementChild!;

        expect(online.className).toContain('bg-success');
        expect(away.className).toContain('bg-transparent');
    });

    it('gives do-not-disturb a distinguishing bar', () => {
        const { container } = render(<PresenceDot status="do_not_disturb" />);

        const dot = container.firstElementChild!.firstElementChild!;

        expect(dot.children.length).toBeGreaterThan(0);
    });
});
