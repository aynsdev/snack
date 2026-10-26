import type { TaskLabel } from '@/types';

/** Mirrors TaskLabel::AGENT and TaskLabel::AGENT_REQUESTED on the server. */
export const AGENT_LABEL = 'agent';
export const AGENT_REQUESTED_LABEL = 'agent-requested';

const RESERVED_LABELS: ReadonlySet<string> = new Set([
    AGENT_LABEL,
    AGENT_REQUESTED_LABEL,
]);

export type AgentState = 'active' | 'requested';

export function isReservedLabel(label: Pick<TaskLabel, 'name'>): boolean {
    return RESERVED_LABELS.has(label.name);
}

export function withoutReservedLabels<T extends Pick<TaskLabel, 'name'>>(
    labels: T[],
): T[] {
    return labels.filter((label) => !isReservedLabel(label));
}

/** Active wins: a card wearing both is one an agent is already working. */
export function agentStateOf(
    labels: ReadonlyArray<Pick<TaskLabel, 'name'>> | undefined,
): AgentState | null {
    const names = new Set((labels ?? []).map((label) => label.name));

    if (names.has(AGENT_LABEL)) {
        return 'active';
    }

    return names.has(AGENT_REQUESTED_LABEL) ? 'requested' : null;
}
