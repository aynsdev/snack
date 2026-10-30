import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { MetaBadge } from '@/components/snack/meta-badge';

/**
 * The badge's explanation reaches screen readers as text, not only as a
 * hover tooltip.
 */
describe('MetaBadge', () => {
    it('repeats its title as text a screen reader announces', () => {
        render(
            <MetaBadge title="Posted by LocalAgent, working for Renz">
                Agent
            </MetaBadge>,
        );

        const badge = screen.getByTitle(
            'Posted by LocalAgent, working for Renz',
        );

        expect(badge).toHaveTextContent(
            'Agent: Posted by LocalAgent, working for Renz',
        );
        expect(
            screen.getByText(': Posted by LocalAgent, working for Renz'),
        ).toHaveClass('sr-only');
    });

    it('adds nothing when there is nothing to explain', () => {
        render(<MetaBadge>Agent</MetaBadge>);

        expect(screen.getByText('Agent')).toHaveTextContent(/^Agent$/);
    });
});
