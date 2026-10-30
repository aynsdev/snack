import { describe, expect, it } from 'vitest';
import { actionablePlanId } from '@/lib/plan-actions';
import type { Message, MessageAgent } from '@/types';

const ME = 7;

function post(
    id: number,
    body: string,
    senderId: number,
    agent: MessageAgent | null = null,
): Pick<Message, 'id' | 'body' | 'agent' | 'sender'> {
    return {
        id,
        body,
        agent,
        sender: { id: senderId } as Message['sender'],
    };
}

const plan = (id: number, assignee: number | null = ME) =>
    post(id, `Plan v${id}`, ME, {
        name: 'LocalAgent',
        kind: 'plan',
        task_id: 3,
        task_assignee_id: assignee,
    });

describe('actionablePlanId', () => {
    it('offers the newest plan to its assignee', () => {
        expect(actionablePlanId([plan(1), plan(2)], ME)).toBe(2);
    });

    it('offers nothing to anybody else, or without a plan', () => {
        expect(actionablePlanId([plan(1, 99)], ME)).toBeNull();
        expect(actionablePlanId([post(1, 'hi', ME)], ME)).toBeNull();
        expect(actionablePlanId([plan(1)], null)).toBeNull();
    });

    it('stops once the assignee has answered the plan', () => {
        expect(
            actionablePlanId([plan(1), post(2, '/approve', ME)], ME),
        ).toBeNull();
        expect(
            actionablePlanId([plan(1), post(2, ' /Cancel please', ME)], ME),
        ).toBeNull();
    });

    it('ignores answers from others, earlier answers, and look-alikes', () => {
        expect(actionablePlanId([plan(1), post(2, '/approve', 99)], ME)).toBe(
            1,
        );
        expect(actionablePlanId([post(1, '/approve', ME), plan(2)], ME)).toBe(
            2,
        );
        expect(
            actionablePlanId([plan(1), post(2, '/approved-ish', ME)], ME),
        ).toBe(1);
    });
});
