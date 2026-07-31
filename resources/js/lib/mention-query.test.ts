import { describe, expect, it } from 'vitest';
import { applyMention, mentionQueryAt } from '@/lib/mention-query';

/**
 * Caret arithmetic for the composer's @-picker.
 *
 * Every case here is one the author sees immediately: the picker opening on the
 * wrong word, or a chosen handle overwriting text they meant to keep.
 */
describe('mentionQueryAt', () => {
    it('finds a handle being typed at the end', () => {
        expect(mentionQueryAt('hi @ad', 6)).toEqual({ term: 'ad', start: 3 });
    });

    it('finds a bare @ with nothing typed yet', () => {
        // The picker should open the moment "@" is typed, not once it matches.
        expect(mentionQueryAt('hi @', 4)).toEqual({ term: '', start: 3 });
    });

    it('finds a handle at the very start of the message', () => {
        expect(mentionQueryAt('@ad', 3)).toEqual({ term: 'ad', start: 0 });
    });

    it('ignores an @ the caret has already moved past', () => {
        // "hi @ada done" with the caret at the end is not a mention in progress;
        // popping a picker over finished text would be an ambush.
        expect(mentionQueryAt('hi @ada done', 12)).toBeNull();
    });

    it('ignores an email address', () => {
        expect(mentionQueryAt('ada@snack', 9)).toBeNull();
    });

    it('ignores a doubled sigil', () => {
        expect(mentionQueryAt('@@ad', 4)).toBeNull();
    });

    it('reads only the text before the caret', () => {
        // Editing mid-sentence, with an unrelated "@" further along.
        expect(mentionQueryAt('hi @ada and @bo', 6)).toEqual({
            term: 'ad',
            start: 3,
        });
    });

    it('returns nothing for a message with no mention', () => {
        expect(mentionQueryAt('just talking', 12)).toBeNull();
    });
});

describe('applyMention', () => {
    it('replaces the partial handle and adds a space', () => {
        expect(applyMention('hi @ad', { term: 'ad', start: 3 }, 'ada')).toEqual(
            {
                value: 'hi @ada ',
                caret: 8,
            },
        );
    });

    it('does not double an existing space', () => {
        expect(
            applyMention('hi @ad there', { term: 'ad', start: 3 }, 'ada'),
        ).toEqual({ value: 'hi @ada there', caret: 7 });
    });

    it('does not put a space before punctuation', () => {
        // "@ada , are you around?" is the tell-tale sign of a composer that
        // inserts blindly. The caret lands right after the handle.
        expect(
            applyMention(
                '@ad, are you around?',
                { term: 'ad', start: 0 },
                'ada',
            ),
        ).toEqual({ value: '@ada, are you around?', caret: 4 });
    });

    it('completes a bare @', () => {
        expect(applyMention('hi @', { term: '', start: 3 }, 'ada')).toEqual({
            value: 'hi @ada ',
            caret: 8,
        });
    });
});
