import { describe, expect, it } from 'vitest';
import { describe as sentence } from '@/components/snack/typing-indicator';

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
});
