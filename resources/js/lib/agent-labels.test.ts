import { describe, expect, it } from 'vitest';
import {
    agentStateOf,
    isReservedLabel,
    withoutReservedLabels,
} from '@/lib/agent-labels';

const label = (name: string) => ({ name });

describe('agent labels', () => {
    it('recognises only the two reserved names', () => {
        expect(isReservedLabel(label('agent'))).toBe(true);
        expect(isReservedLabel(label('agent-requested'))).toBe(true);
        expect(isReservedLabel(label('Agent'))).toBe(false);
        expect(isReservedLabel(label('agents'))).toBe(false);
    });

    it('drops the reserved labels and keeps the order of the rest', () => {
        expect(
            withoutReservedLabels([
                label('backend'),
                label('agent'),
                label('ux'),
                label('agent-requested'),
            ]),
        ).toEqual([label('backend'), label('ux')]);
    });

    it('reads the agent state off a card', () => {
        expect(agentStateOf(undefined)).toBeNull();
        expect(agentStateOf([label('backend')])).toBeNull();
        expect(agentStateOf([label('agent-requested')])).toBe('requested');
        expect(agentStateOf([label('agent')])).toBe('active');
        expect(agentStateOf([label('agent-requested'), label('agent')])).toBe(
            'active',
        );
    });
});
