import type { Message } from '@/types';

type ThreadMessage = Pick<Message, 'id' | 'body' | 'agent' | 'sender'>;

const DECISION = /^\/(approve|cancel)\b/i;

/**
 * The plan in a thread that `viewerId` may still approve or cancel: the
 * newest plan, if they are its task's assignee and have not already answered
 * it with `/approve` or `/cancel`.
 */
export function actionablePlanId(
    messages: readonly ThreadMessage[],
    viewerId: number | null,
): number | null {
    if (viewerId === null) {
        return null;
    }

    let planIndex = -1;

    for (let index = messages.length - 1; index >= 0; index--) {
        if (messages[index].agent?.kind === 'plan') {
            planIndex = index;
            break;
        }
    }

    const plan = messages[planIndex];

    if (plan === undefined || plan.agent?.task_assignee_id !== viewerId) {
        return null;
    }

    const answered = messages
        .slice(planIndex + 1)
        .some(
            (message) =>
                message.sender?.id === viewerId &&
                message.agent == null &&
                DECISION.test(message.body.trim()),
        );

    return answered ? null : plan.id;
}
