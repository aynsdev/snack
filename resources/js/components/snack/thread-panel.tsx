import { Bell, BellOff, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef } from 'react';
import type { MentionSource } from '@/components/snack/mention-picker';
import { MessageComposer } from '@/components/snack/message-composer';
import {
    MessageItem,
    PendingMessageItem,
} from '@/components/snack/message-item';
import { PlanActions } from '@/components/snack/plan-actions';
import { RightPanel } from '@/components/snack/right-panel';
import { Skeleton } from '@/components/ui/skeleton';
import { useBiteReplying } from '@/hooks/use-bite-replying';
import { useThread } from '@/hooks/use-thread';
import { channels } from '@/lib/echo';
import { continuesGroup, pendingContinuesGroup } from '@/lib/message-grouping';
import { actionablePlanId } from '@/lib/plan-actions';
import { cn } from '@/lib/utils';
import type { ReactionGroup, SnackUser } from '@/types';

/**
 * A thread, in the right-hand panel.
 *
 * # Why the root is rendered apart from the replies
 *
 * The root is the thing being discussed, not the first thing said about it. It
 * sits above a rule, ungrouped and never collapsed into the replies below, so
 * the panel reads as "this message, and what came back" rather than as a second
 * timeline that happens to start somewhere arbitrary.
 *
 * # Scrolling
 *
 * Deliberately unlike the timeline. A thread is opened to be read from the top,
 * so it does *not* jump to the bottom on open — that would land the reader past
 * the message they clicked. It only follows new replies that arrive while they
 * are already at the bottom.
 */
export function ThreadPanel({
    rootId,
    workspaceSlug,
    sendUrl,
    containerName,
    mentionSource,
    currentUser,
    canPost,
    onClose,
}: {
    rootId: number;
    workspaceSlug: string;
    /** The container's message-store URL — replies post through the one send path. */
    sendUrl: string;
    /** For the composer placeholder: "Reply in #design". */
    containerName: string;
    /** Who the reply composer can @mention — the same roster as the container's. */
    mentionSource?: MentionSource;
    currentUser: SnackUser | null;
    canPost: boolean;
    onClose: () => void;
}) {
    const thread = useThread({ rootId, workspaceSlug, sendUrl, currentUser });

    /*
     * A summon inside a thread is answered inside that thread, so this is where
     * the wait happens. Private rather than presence: a thread has no roster of
     * its own — see routes/channels.php.
     */
    const replying = useBiteReplying({
        channelName: channels.thread(rootId),
        visibility: 'private',
    });

    const viewerId = currentUser?.id ?? null;
    const { replies, reply: sendReply } = thread;

    const planId = useMemo(
        () => (canPost ? actionablePlanId(replies, viewerId) : null),
        [canPost, replies, viewerId],
    );

    const sendDecision = useCallback(
        (body: string) => sendReply(body),
        [sendReply],
    );

    const scroller = useRef<HTMLDivElement>(null);
    const atBottom = useRef(true);

    const newestId = thread.replies.at(-1)?.id;

    /*
     * Pulled out of the object so the read-sync effect below depends on the
     * callback rather than on `thread`, which is a fresh object every render and
     * would fire the effect on each one.
     */
    const { markAsRead, pending } = thread;

    // Reacting needs posting rights, same as replying, so the control is absent
    // wherever the composer is rather than offering a button that 403s.
    const onReact = canPost
        ? (id: number, emoji: string, current: ReactionGroup[]) =>
              void thread.react(id, emoji, current).catch(() => undefined)
        : undefined;

    /*
     * Follow new replies only if the reader was already at the bottom. Yanking
     * someone away from the middle of a thread they are reading is worse than
     * making them scroll.
     */
    useEffect(() => {
        const element = scroller.current;

        if (element !== null && atBottom.current) {
            element.scrollTop = element.scrollHeight;
        }
    }, [newestId, pending.length]);

    /*
     * Sync the read pointer when the newest reply changes while the reader is at
     * the bottom — the only moment they have demonstrably seen it.
     */
    useEffect(() => {
        if (atBottom.current) {
            markAsRead();
        }
    }, [newestId, markAsRead]);

    const recordPosition = () => {
        const element = scroller.current;

        if (element === null) {
            return;
        }

        atBottom.current =
            element.scrollHeight - element.scrollTop - element.clientHeight <
            80;
    };

    return (
        <RightPanel
            title="Thread"
            onClose={onClose}
            resizeKey={`thread:${currentUser?.id ?? 'guest'}`}
        >
            <div className="flex h-full min-h-0 flex-col">
                <div
                    ref={scroller}
                    onScroll={recordPosition}
                    className="snack-scroll min-h-0 flex-1 overflow-y-auto"
                >
                    {thread.loading && <ThreadSkeleton />}

                    {thread.error !== null && (
                        <p className="px-4 py-6 text-sm text-muted-foreground">
                            {thread.error}
                        </p>
                    )}

                    {thread.root !== null && (
                        <>
                            <div className="pt-2">
                                <MessageItem
                                    message={thread.root}
                                    grouped={false}
                                    isOwn={
                                        thread.root.sender?.id ===
                                        currentUser?.id
                                    }
                                    onEdit={thread.edit}
                                    onDelete={thread.remove}
                                    onReact={onReact}
                                    currentUserId={currentUser?.id ?? null}
                                />
                            </div>

                            <ReplyCountRule count={thread.root.reply_count} />

                            {thread.hasMore && (
                                <div className="px-4 pb-2">
                                    <button
                                        type="button"
                                        onClick={() => void thread.loadMore()}
                                        className="text-xs font-semibold text-brand hover:underline"
                                    >
                                        Load earlier replies
                                    </button>
                                </div>
                            )}

                            <div className="pb-2">
                                {thread.replies.map((reply, index) => (
                                    <MessageItem
                                        key={reply.id}
                                        message={reply}
                                        grouped={continuesGroup(
                                            index > 0
                                                ? thread.replies[index - 1]
                                                : null,
                                            reply,
                                        )}
                                        isOwn={
                                            reply.sender?.id === currentUser?.id
                                        }
                                        onEdit={thread.edit}
                                        onDelete={thread.remove}
                                        onReact={onReact}
                                        currentUserId={currentUser?.id ?? null}
                                        footer={
                                            reply.id === planId ? (
                                                <PlanActions
                                                    key={reply.id}
                                                    onSend={sendDecision}
                                                />
                                            ) : undefined
                                        }
                                    />
                                ))}

                                {thread.pending.map((reply, index) => (
                                    <PendingMessageItem
                                        key={reply.nonce}
                                        message={reply}
                                        grouped={
                                            index === 0
                                                ? pendingContinuesGroup(
                                                      thread.replies.at(-1) ??
                                                          null,
                                                      reply,
                                                  )
                                                : true
                                        }
                                        onRetry={() =>
                                            thread.retry(reply.nonce)
                                        }
                                        onDiscard={() =>
                                            thread.discard(reply.nonce)
                                        }
                                    />
                                ))}
                            </div>
                        </>
                    )}
                </div>

                {thread.root !== null && (
                    <div className="shrink-0 border-t border-border">
                        <MessageComposer
                            statusControl={
                                <FollowToggle
                                    following={
                                        thread.subscription.is_subscribed
                                    }
                                    onToggle={() =>
                                        void thread
                                            .setFollowing(
                                                !thread.subscription
                                                    .is_subscribed,
                                            )
                                            .catch(() => undefined)
                                    }
                                />
                            }
                            channelName={`this thread in ${containerName}`}
                            mentionSource={mentionSource}
                            replying={replying}
                            workspaceSlug={workspaceSlug}
                            disabled={!canPost}
                            disabledReason={
                                canPost
                                    ? undefined
                                    : 'You cannot reply in this conversation.'
                            }
                            onSend={(body, attachmentIds) =>
                                void thread.reply(body, attachmentIds)
                            }
                        />
                    </div>
                )}
            </div>
        </RightPanel>
    );
}

/**
 * The rule between the root and its replies, labelled with the count.
 *
 * Doubles as the empty state: a thread opened on a message nobody has answered
 * yet says so, rather than showing a bare gap the reader has to interpret.
 */
function ReplyCountRule({ count }: { count: number }) {
    return (
        <div className="flex items-center gap-3 px-4 py-2">
            <span className="text-xs font-semibold text-muted-foreground">
                {count === 0
                    ? 'No replies yet'
                    : count === 1
                      ? '1 reply'
                      : `${count} replies`}
            </span>

            <span aria-hidden="true" className="h-px flex-1 bg-border" />
        </div>
    );
}

/**
 * Following is normally implicit — replying subscribes you — so this exists for
 * the two cases the implicit rule gets wrong: watching a thread without
 * answering it, and silencing one you answered weeks ago.
 */
function FollowToggle({
    following,
    onToggle,
}: {
    following: boolean;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onToggle}
            aria-pressed={following}
            className={cn(
                'flex shrink-0 items-center gap-2 rounded-md px-1 py-1 text-xs font-semibold transition-colors',
                following
                    ? 'text-foreground hover:bg-accent'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
            )}
        >
            {following ? (
                <Bell aria-hidden="true" className="size-3.5" />
            ) : (
                <BellOff aria-hidden="true" className="size-3.5" />
            )}

            {following ? 'Following this thread' : 'Not following'}
        </button>
    );
}

function ThreadSkeleton() {
    return (
        <div className="space-y-4 px-4 py-4">
            {[0, 1, 2].map((row) => (
                <div key={row} className="flex gap-2.5">
                    <Skeleton className="size-9 shrink-0 rounded-lg" />

                    <div className="flex-1 space-y-2">
                        <Skeleton className="h-3 w-24" />
                        <Skeleton className="h-3 w-full" />
                        <Skeleton className="h-3 w-2/3" />
                    </div>
                </div>
            ))}

            <span className="sr-only">
                <Loader2 aria-label="Loading thread" />
            </span>
        </div>
    );
}
