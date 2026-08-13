import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    TypingIndicator,
    describe as sentence,
} from '@/components/snack/typing-indicator';

/**
 * The typing sentence.
 *
 * Small, but exactly the sort of string that reads fine in the one case anyone
 * checks by hand and ungrammatically in every other.
 */
function typists(...names: string[]) {
    return names.map((name, index) => ({ id: index + 1, name }));
}

describe('typing sentence', () => {
    it('says nothing when nobody is typing', () => {
        expect(sentence([])).toBe('');
    });

    it('uses the singular for one person', () => {
        expect(sentence(typists('Ada'))).toBe('Ada is typing…');
    });

    it('joins two people with "and"', () => {
        expect(sentence(typists('Ada', 'Bo'))).toBe('Ada and Bo are typing…');
    });

    it('collapses to a count past two', () => {
        expect(sentence(typists('Ada', 'Bo', 'Cy'))).toBe(
            'Ada, Bo and 1 other are typing…',
        );
    });

    it('pluralizes the remainder', () => {
        expect(sentence(typists('Ada', 'Bo', 'Cy', 'Dee'))).toBe(
            'Ada, Bo and 2 others are typing…',
        );
    });

    /*
     * The assistant gets its own verb. It is not typing — it is thinking, and
     * then posting all at once — and folding it into the list of names would
     * describe a colleague's keyboard and a queue worker as the same activity.
     */
    it('says the assistant is replying', () => {
        expect(sentence([], 'Bite')).toBe('Bite is replying…');
    });

    it('keeps both clauses when somebody types while the assistant works', () => {
        expect(sentence(typists('Ada'), 'Bite')).toBe(
            'Ada is typing… · Bite is replying…',
        );
    });
});

describe('TypingIndicator', () => {
    it('animates three dots while somebody is typing', () => {
        const { container } = render(
            <TypingIndicator typists={typists('Ada')} />,
        );

        expect(container.querySelectorAll('.snack-typing-dot')).toHaveLength(3);
        expect(screen.getByText('Ada is typing…')).toBeInTheDocument();
    });

    it('renders no dots when nobody is typing', () => {
        const { container } = render(<TypingIndicator typists={[]} />);

        expect(container.querySelectorAll('.snack-typing-dot')).toHaveLength(0);
    });

    it('animates the dots while the assistant is working', () => {
        const { container } = render(
            <TypingIndicator typists={[]} replying="Bite" />,
        );

        expect(container.querySelectorAll('.snack-typing-dot')).toHaveLength(3);
        expect(screen.getByText('Bite is replying…')).toBeInTheDocument();
    });

    it('keeps the dots out of the accessibility tree', () => {
        /*
         * The row is aria-live, so anything announceable inside it is read out
         * every time somebody starts typing. The sentence beside the dots
         * already carries the whole meaning.
         */
        const { container } = render(
            <TypingIndicator typists={typists('Ada')} />,
        );

        expect(
            container
                .querySelector('.snack-typing-dot')
                ?.closest('[aria-hidden="true"]'),
        ).not.toBeNull();
    });

    it('holds its line whether or not anyone is typing', () => {
        // A row that appears and disappears would shove the composer up and
        // down by a line while somebody types at you.
        const quiet = render(<TypingIndicator typists={[]} />);
        const busy = render(<TypingIndicator typists={typists('Ada')} />);

        const height = (view: typeof quiet) =>
            view.container.firstElementChild?.className.includes('h-4');

        expect(height(quiet)).toBe(true);
        expect(height(busy)).toBe(true);
    });
});
