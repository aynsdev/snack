import { useCallback } from 'react';
import { api } from '@/lib/api';
import {
    destroy as removeReaction,
    store as addReaction,
} from '@/routes/reactions';
import type { ReactionGroup } from '@/types';

/**
 * How a message list applies a new reaction summary to one of its messages.
 *
 * Supplied by the caller so this hook knows nothing about whether it is driving
 * a timeline or a thread panel.
 */
export type ApplyReactions = (
    messageId: number,
    reactions: ReactionGroup[],
) => void;

/**
 * Adding and removing reactions, shared by the timeline and the thread panel.
 *
 * # Why this is one hook and not two implementations
 *
 * The two lists differ in how they *page* and what they *subscribe to*, which is
 * why they own separate hooks. Reacting differs in neither: it is the same two
 * endpoints, the same optimistic update, and the same rollback. Written twice it
 * would be two chances to get the rollback subtly wrong, and a reaction that
 * sticks after the server refused it is a lie the user acts on.
 *
 * # Optimism, and its one rule
 *
 * The pill updates before the request lands, because a reaction that waits for a
 * round trip feels broken. The optimistic value is computed from the summary the
 * client currently holds; the server's response replaces it wholesale, and a
 * failure restores exactly what was there before. That means a rejected reaction
 * never leaves a phantom pill — the case people notice, because they are usually
 * looking straight at it.
 */
export function useReactions({
    workspaceSlug,
    currentUserId,
    apply,
}: {
    workspaceSlug: string;
    currentUserId: number | null;
    apply: ApplyReactions;
}) {
    const toggle = useCallback(
        async (
            messageId: number,
            emoji: string,
            current: ReactionGroup[],
        ): Promise<void> => {
            if (currentUserId === null) {
                return;
            }

            const mine =
                current
                    .find((group) => group.emoji === emoji)
                    ?.user_ids.includes(currentUserId) ?? false;

            apply(messageId, optimistic(current, emoji, currentUserId, !mine));

            try {
                const args = { workspace: workspaceSlug, message: messageId };

                /*
                 * The emoji rides in the query string on the way out, because a
                 * DELETE carries no body. Laravel's request reads query and
                 * body through the same accessor, so ReactionRequest validates
                 * it identically either way.
                 */
                const { reactions } = mine
                    ? await api.delete<{ reactions: ReactionGroup[] }>(
                          removeReaction.url(args, { query: { emoji } }),
                      )
                    : await api.post<{ reactions: ReactionGroup[] }>(
                          addReaction.url(args),
                          { emoji },
                      );

                apply(messageId, reactions);
            } catch (cause) {
                apply(messageId, current);

                throw cause;
            }
        },
        [apply, currentUserId, workspaceSlug],
    );

    return { toggle };
}

/**
 * What the summary should look like the instant the pill is clicked.
 *
 * Kept pure and exported for testing: this is the arithmetic that decides
 * whether a pill appears, increments, decrements, or disappears, and every one
 * of those transitions is visible to the user immediately.
 */
export function optimistic(
    current: ReactionGroup[],
    emoji: string,
    userId: number,
    adding: boolean,
): ReactionGroup[] {
    const existing = current.find((group) => group.emoji === emoji);

    if (existing === undefined) {
        // Removing something that is not there is already the desired state.
        return adding
            ? [
                  ...current,
                  { emoji, count: 1, user_ids: [userId], names: ['You'] },
              ]
            : current;
    }

    const next = adding
        ? {
              ...existing,
              count: existing.count + 1,
              user_ids: [...existing.user_ids, userId],
          }
        : {
              ...existing,
              count: Math.max(existing.count - 1, 0),
              user_ids: existing.user_ids.filter((id) => id !== userId),
          };

    // The last reactor leaving takes the pill with it rather than leaving a
    // zero behind.
    return next.count === 0
        ? current.filter((group) => group.emoji !== emoji)
        : current.map((group) => (group.emoji === emoji ? next : group));
}
