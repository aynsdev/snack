import { useEchoPresence } from '@laravel/echo-react';
import { useCallback, useState } from 'react';
import { api } from '@/lib/api';
import { events } from '@/lib/echo';
import { destroy as unpinMessage, store as pinMessage } from '@/routes/pins';
import { destroy as unsaveMessage, store as saveMessage } from '@/routes/saves';
import type { Message, PinChangedEvent } from '@/types';

/**
 * The pins on a container, and the viewer's private saves.
 *
 * Kept together because they are the same gesture from the user's side and
 * nothing alike underneath — which is exactly the pair worth writing in one
 * place, where the difference is visible:
 *
 *  - a **pin** is shared. It changes what everyone in the room sees, so the
 *    server broadcasts it and this hook keeps a list that any subscriber can
 *    change.
 *  - a **save** is private. Nothing is broadcast, and the only state to track is
 *    a flag on the viewer's own copy of the message.
 */
export function usePins({
    broadcastChannel,
    workspaceSlug,
    initial,
    onSaveChanged,
}: {
    broadcastChannel: string;
    workspaceSlug: string;
    initial: Message[];
    /** Lets the timeline flip `is_saved` on its own copy of the message. */
    onSaveChanged: (messageId: number, isSaved: boolean) => void;
}) {
    const [pinned, setPinned] = useState<Message[]>(initial);

    /*
     * A pin changed somewhere in this container. The payload carries the whole
     * message, so the bar can redraw without a follow-up request — and the list
     * is rebuilt from it rather than patched, so a client that missed an earlier
     * broadcast is corrected rather than left drifting.
     */
    useEchoPresence<PinChangedEvent>(
        broadcastChannel,
        events.pinChanged,
        (payload) =>
            setPinned((current) => {
                const without = current.filter(
                    (message) => message.id !== payload.message.id,
                );

                return payload.pinned
                    ? [{ ...payload.message, is_pinned: true }, ...without]
                    : without;
            }),
        [broadcastChannel],
    );

    const togglePin = useCallback(
        async (message: Message) => {
            const args = { workspace: workspaceSlug, message: message.id };

            const { pinned: next } = message.is_pinned
                ? await api.delete<{ pinned: Message[] }>(
                      unpinMessage.url(args),
                  )
                : await api.post<{ pinned: Message[] }>(pinMessage.url(args));

            // The endpoint answers with the container's whole pin list, which is
            // what the bar renders — so this needs no second request.
            setPinned(next);
        },
        [workspaceSlug],
    );

    const toggleSave = useCallback(
        async (message: Message) => {
            const args = { workspace: workspaceSlug, message: message.id };
            const saving = !(message.is_saved ?? false);

            // Optimistic: a bookmark that waits for a round trip feels broken,
            // and there is no shared state to get wrong.
            onSaveChanged(message.id, saving);

            try {
                const { is_saved } = saving
                    ? await api.post<{ is_saved: boolean }>(
                          saveMessage.url(args),
                      )
                    : await api.delete<{ is_saved: boolean }>(
                          unsaveMessage.url(args),
                      );

                onSaveChanged(message.id, is_saved);
            } catch (cause) {
                onSaveChanged(message.id, !saving);

                throw cause;
            }
        },
        [onSaveChanged, workspaceSlug],
    );

    return { pinned, togglePin, toggleSave };
}
