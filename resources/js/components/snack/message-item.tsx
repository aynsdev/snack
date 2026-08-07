import {
    Bookmark,
    BookmarkCheck,
    MessageSquareReply,
    Pencil,
    Pin,
    PinOff,
    RotateCw,
    SmilePlus,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { AttachmentList } from '@/components/snack/attachment-list';
import { MessageText } from '@/components/snack/message-text';
import { StandupCard } from '@/components/snack/standup-card';
import { UserAvatar } from '@/components/snack/user-avatar';
import { WebhookAttachments } from '@/components/snack/webhook-attachments';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import { REACTION_SHORTCODES, emojiGlyph, reactionTooltip } from '@/lib/emoji';
import { cn } from '@/lib/utils';
import type { Message, PendingMessage, ReactionGroup } from '@/types';

/**
 * Time formatting.
 *
 * Built once at module scope — `Intl` formatters are expensive to construct,
 * and a busy channel renders hundreds of timestamps.
 */
const TIME = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
});

const FULL = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'full',
    timeStyle: 'short',
});

export function formatTime(iso: string | null): string {
    return iso === null ? '' : TIME.format(new Date(iso));
}

/**
 * One message.
 *
 * Consecutive messages from the same author collapse into a group: only the
 * first carries an avatar and a name, and the rest indent to align under it.
 * The timestamp on a grouped message appears in the gutter on hover, so the
 * information is reachable without a column of repeated clock faces.
 */
export function MessageItem({
    message,
    grouped,
    isOwn,
    onEdit,
    onDelete,
    onOpenThread,
    onReact,
    onTogglePin,
    onToggleSave,
    currentUserId = null,
}: {
    message: Message;
    grouped: boolean;
    isOwn: boolean;
    onEdit: (id: number, body: string) => Promise<void>;
    onDelete: (id: number) => void;
    /**
     * Omitted inside a thread panel, where every reply is already in the thread
     * and a "reply in thread" control would only ever reopen the panel it was
     * clicked in.
     */
    onOpenThread?: (message: Message) => void;
    /** Omitted where the viewer has no posting rights, e.g. a channel preview. */
    onReact?: (
        messageId: number,
        emoji: string,
        current: ReactionGroup[],
    ) => void;
    /** Omitted where the viewer lacks pinning rights on the container. */
    onTogglePin?: (message: Message) => void;
    /** Omitted in lists where a bookmark has nowhere to show. */
    onToggleSave?: (message: Message) => void;
    /** Decides which pills render as "yours"; the server re-authorizes anyway. */
    currentUserId?: number | null;
}) {
    const [editing, setEditing] = useState(false);

    /*
     * Live-arriving messages have no `can` from the server — one broadcast
     * cannot carry per-viewer permissions — so authorship stands in as the
     * hint. It only decides whether a button renders; the server re-authorizes
     * the request and answers 403 if the hint was wrong.
     */
    const canEdit = message.can?.update ?? isOwn;
    const canDelete = message.can?.delete ?? isOwn;
    // A live-arriving message carries no abilities; pinning is offered on the
    // assumption the viewer may, and refused server-side if they may not.
    const canPin = message.can?.pin ?? true;

    if (message.type === 'system') {
        return <SystemMessage message={message} />;
    }

    return (
        <article
            className={cn(
                'group relative flex gap-2.5 px-4 hover:bg-accent/40',
                grouped ? 'py-0.5' : 'mt-3 py-1',
            )}
        >
            <div className="w-9 shrink-0 pt-0.5">
                {grouped ? (
                    /*
                     * Faded rather than hidden, and that is the whole point.
                     * `hidden` takes the timestamp out of flow, so revealing it
                     * on hover grew the row — the gutter is narrower than
                     * "10:51 PM", so the line wrapped in two and the message
                     * jumped under the pointer. Kept in flow at zero opacity it
                     * costs the same height whether or not anyone is hovering,
                     * which is what the virtualizer measures.
                     *
                     * Right-aligned and unwrapped: a long time string overflows
                     * leftwards into the row's own padding, where there is room
                     * for it, instead of into the message body.
                     */
                    <time
                        dateTime={message.created_at ?? undefined}
                        className="block text-right text-[0.625rem] leading-5 whitespace-nowrap text-muted-foreground tabular-nums opacity-0 transition-opacity group-hover:opacity-100"
                    >
                        {formatTime(message.created_at)}
                    </time>
                ) : (
                    message.sender && (
                        <UserAvatar user={message.sender} size="md" />
                    )
                )}
            </div>

            <div className="min-w-0 flex-1">
                {!grouped && (
                    <div className="flex items-baseline gap-2">
                        <span className="text-sm font-bold">
                            {/*
                             * The webhook's snapshotted name wins over the
                             * sender's current one. The bot account follows the
                             * hook when an admin renames it, so the sender is
                             * "what this integration is called now" while this
                             * is "what posted this message" — and on an old
                             * deploy notice those are different facts.
                             */}
                            {message.webhook?.username ??
                                message.sender?.name ??
                                'Snack'}
                        </span>

                        {message.webhook != null && (
                            /*
                             * Said plainly, because the name beside it is a
                             * string an unauthenticated caller chose. A payload
                             * cannot pick the *account* it posts as, but it can
                             * claim any display name it likes — so the badge is
                             * what stops "Ada Lovelace" in a channel from
                             * reading as Ada.
                             */
                            <span className="rounded bg-muted px-1 py-px text-[0.625rem] font-semibold text-muted-foreground uppercase">
                                App
                            </span>
                        )}

                        <time
                            dateTime={message.created_at ?? undefined}
                            title={
                                message.created_at
                                    ? FULL.format(new Date(message.created_at))
                                    : undefined
                            }
                            className="text-xs text-muted-foreground tabular-nums"
                        >
                            {formatTime(message.created_at)}
                        </time>
                    </div>
                )}

                {editing ? (
                    <MessageEditor
                        initialBody={message.body}
                        onCancel={() => setEditing(false)}
                        onSave={async (body) => {
                            await onEdit(message.id, body);
                            setEditing(false);
                        }}
                    />
                ) : (
                    <>
                        {/*
                         * A message may legitimately have no words at all —
                         * files alone are a message — so the body is only
                         * rendered when there is one, rather than leaving an
                         * empty line above the attachments.
                         */}
                        {message.body !== '' && (
                            <MessageText body={message.body} />
                        )}

                        {message.is_edited && (
                            <span className="ml-1 text-[0.6875rem] text-muted-foreground">
                                (edited)
                            </span>
                        )}

                        {/*
                         * The standup form, on the bot's prompts and reminders
                         * only — `standup` is null on every other message.
                         *
                         * Below the body rather than instead of it: the body
                         * carries the questions too, which is what search
                         * indexes, what the sidebar previews, and what somebody
                         * reading on a client that has never heard of standup
                         * cards still sees.
                         */}
                        {message.standup != null && (
                            <StandupCard
                                key={message.standup.response_id}
                                card={message.standup}
                            />
                        )}

                        {/*
                         * The cards an outside system sent, below the body for
                         * the reason the standup form is: the body carries the
                         * headline too, which is what search indexes, what the
                         * sidebar previews, and what a notification reads out.
                         */}
                        {message.webhook != null && (
                            <WebhookAttachments
                                attachments={message.webhook.attachments}
                            />
                        )}

                        <AttachmentList attachments={message.attachments} />

                        {message.reactions.length > 0 &&
                            onReact !== undefined && (
                                <ReactionBar
                                    reactions={message.reactions}
                                    currentUserId={currentUserId}
                                    onToggle={(emoji) =>
                                        onReact(
                                            message.id,
                                            emoji,
                                            message.reactions,
                                        )
                                    }
                                />
                            )}

                        {message.reply_count > 0 &&
                            onOpenThread !== undefined && (
                                <ThreadAffordance
                                    message={message}
                                    onOpen={() => onOpenThread(message)}
                                />
                            )}
                    </>
                )}
            </div>

            {!editing && (
                <MessageActions
                    canEdit={canEdit}
                    canDelete={canDelete}
                    isPinned={message.is_pinned}
                    isSaved={message.is_saved ?? false}
                    onEdit={() => setEditing(true)}
                    onDelete={() => onDelete(message.id)}
                    onReply={
                        onOpenThread === undefined
                            ? undefined
                            : () => onOpenThread(message)
                    }
                    onReact={
                        onReact === undefined
                            ? undefined
                            : (emoji) =>
                                  onReact(message.id, emoji, message.reactions)
                    }
                    onPin={
                        onTogglePin === undefined || !canPin
                            ? undefined
                            : () => onTogglePin(message)
                    }
                    onSave={
                        onToggleSave === undefined
                            ? undefined
                            : () => onToggleSave(message)
                    }
                />
            )}
        </article>
    );
}

/**
 * Something Snack did rather than something a person said: "Ada added Bo".
 *
 * Deliberately quieter than a message and without an avatar, because it has no
 * author — the row genuinely has no sender. It still sits in the timeline in
 * posting order, since its whole purpose is to explain, later, why the
 * participant list changed when it did.
 *
 * No hover actions: a system notice cannot be edited, deleted, or reacted to,
 * which {@see App\Enums\MessageType::isAuthoredByUser()} enforces server-side.
 */
function SystemMessage({ message }: { message: Message }) {
    return (
        <div className="flex gap-2.5 px-4 py-1">
            <span aria-hidden="true" className="w-9 shrink-0" />

            <p className="min-w-0 flex-1 text-sm text-muted-foreground">
                <MessageText body={message.body} />

                <time
                    dateTime={message.created_at ?? undefined}
                    title={
                        message.created_at === null
                            ? undefined
                            : FULL.format(new Date(message.created_at))
                    }
                    className="ml-1.5 text-[0.625rem] tabular-nums"
                >
                    {formatTime(message.created_at)}
                </time>
            </p>
        </div>
    );
}

/**
 * A message the user has sent that the server has not confirmed yet.
 *
 * Rendered dimmed rather than withheld: the point of an optimistic send is that
 * the conversation keeps its rhythm. A failure turns it into something the
 * author can retry or discard — never a message that quietly disappears after
 * they have already moved on.
 */
export function PendingMessageItem({
    message,
    grouped,
    onRetry,
    onDiscard,
}: {
    message: PendingMessage;
    grouped: boolean;
    onRetry: () => void;
    onDiscard: () => void;
}) {
    return (
        <article
            className={cn(
                'group relative flex gap-2.5 px-4',
                grouped ? 'py-0.5' : 'mt-3 py-1',
            )}
        >
            <div className="w-9 shrink-0 pt-0.5">
                {!grouped && (
                    <UserAvatar
                        user={message.sender}
                        size="md"
                        className={message.failed ? '' : 'opacity-60'}
                    />
                )}
            </div>

            <div className="min-w-0 flex-1">
                {!grouped && (
                    <div className="flex items-baseline gap-2">
                        <span className="text-sm font-bold">
                            {message.sender.name}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {message.failed ? 'Not sent' : 'Sending…'}
                        </span>
                    </div>
                )}

                {message.body !== '' && (
                    <MessageText
                        body={message.body}
                        className={
                            message.failed ? 'text-danger' : 'opacity-60'
                        }
                    />
                )}

                {/*
                 * The files themselves are already on the server — they were
                 * uploaded from the tray — so there is nothing to preview here,
                 * only a count. A files-only message would otherwise render as
                 * a blank row while the send is in flight.
                 */}
                {message.attachmentCount > 0 && (
                    <p
                        className={cn(
                            'text-sm',
                            message.failed
                                ? 'text-danger'
                                : 'text-muted-foreground opacity-60',
                        )}
                    >
                        {message.attachmentCount === 1
                            ? '1 file'
                            : `${message.attachmentCount} files`}
                    </p>
                )}

                {message.failed && (
                    <div className="mt-1 flex items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={onRetry}
                            className="h-7 gap-1.5 px-2 text-xs"
                        >
                            <RotateCw aria-hidden="true" className="size-3" />
                            Try again
                        </Button>

                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={onDiscard}
                            className="h-7 px-2 text-xs text-muted-foreground"
                        >
                            Discard
                        </Button>
                    </div>
                )}
            </div>
        </article>
    );
}

/**
 * The entry point into a thread, shown under a message that already has one.
 *
 * Deliberately states the count rather than showing a bare icon: "4 replies" is
 * the reason to click, and a thread nobody has answered yet has no affordance at
 * all — an empty one on every message would be noise on the overwhelming
 * majority that never get a reply.
 */
function ThreadAffordance({
    message,
    onOpen,
}: {
    message: Message;
    onOpen: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className="mt-1 flex items-center gap-1.5 rounded-md px-1.5 py-1 text-xs font-semibold text-brand transition-colors hover:bg-brand/10"
        >
            <MessageSquareReply aria-hidden="true" className="size-3.5" />

            {message.reply_count === 1
                ? '1 reply'
                : `${message.reply_count} replies`}

            {message.last_reply_at !== null && (
                <span className="font-normal text-muted-foreground">
                    Last reply {formatTime(message.last_reply_at)}
                </span>
            )}
        </button>
    );
}

/**
 * The reaction pills under a message.
 *
 * A pill the viewer is part of is outlined in the brand colour — the one piece
 * of state that decides whether clicking adds or removes, so it has to be
 * legible at a glance rather than only in the tooltip.
 */
function ReactionBar({
    reactions,
    currentUserId,
    onToggle,
}: {
    reactions: ReactionGroup[];
    currentUserId: number | null;
    onToggle: (emoji: string) => void;
}) {
    return (
        <div className="mt-1 flex flex-wrap items-center gap-1">
            {reactions.map((group) => {
                const mine =
                    currentUserId !== null &&
                    group.user_ids.includes(currentUserId);

                return (
                    <button
                        key={group.emoji}
                        type="button"
                        onClick={() => onToggle(group.emoji)}
                        aria-pressed={mine}
                        title={reactionTooltip(
                            group.names,
                            group.count,
                            group.emoji,
                        )}
                        className={cn(
                            'flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition-colors',
                            mine
                                ? 'border-brand bg-brand-subtle text-foreground'
                                : 'border-border bg-muted/40 text-muted-foreground hover:border-brand/40',
                        )}
                    >
                        <span aria-hidden="true">
                            {emojiGlyph(group.emoji)}
                        </span>
                        <span className="font-semibold tabular-nums">
                            {group.count}
                        </span>
                        <span className="sr-only">
                            {reactionTooltip(
                                group.names,
                                group.count,
                                group.emoji,
                            )}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

/**
 * The quick-reaction picker in the hover toolbar.
 *
 * A plain popover of the configured set rather than a search-and-scroll emoji
 * keyboard: reactions are a two-second gesture, and anything that turns them
 * into a task gets used once. The full set is small enough to show at once.
 */
function ReactionPicker({ onPick }: { onPick: (emoji: string) => void }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <SmilePlus aria-hidden="true" className="size-3.5" />
                    <span className="sr-only">Add reaction</span>
                </button>
            </DropdownMenuTrigger>

            {/*
             * Rendered through the project's dropdown primitive rather than as a
             * positioned div, because it has to escape the timeline's
             * `overflow-y-auto`. An absolutely-positioned popover is clipped by
             * any scrolling ancestor no matter how high its z-index — z-index
             * does not lift an element out of an overflow box — so the picker
             * was cut off for every message near the edge of the viewport.
             *
             * The primitive portals to the body and flips side automatically
             * when there is no room below, which also removes the hand-rolled
             * click-outside backdrop this used to need.
             */}
            <DropdownMenuContent
                align="end"
                className="flex w-56 flex-wrap gap-0.5 p-1.5"
            >
                {REACTION_SHORTCODES.map((shortcode) => (
                    <button
                        key={shortcode}
                        type="button"
                        title={`:${shortcode}:`}
                        onClick={() => onPick(shortcode)}
                        className="rounded p-1 text-base transition-colors hover:bg-accent"
                    >
                        <span aria-hidden="true">{emojiGlyph(shortcode)}</span>
                        <span className="sr-only">{shortcode}</span>
                    </button>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function MessageActions({
    canEdit,
    canDelete,
    isPinned,
    isSaved,
    onEdit,
    onDelete,
    onReply,
    onReact,
    onPin,
    onSave,
}: {
    canEdit: boolean;
    canDelete: boolean;
    isPinned: boolean;
    isSaved: boolean;
    onEdit: () => void;
    onDelete: () => void;
    onReply?: () => void;
    onReact?: (emoji: string) => void;
    onPin?: () => void;
    onSave?: () => void;
}) {
    // Nothing to reveal at all — a system message in a read-only view.
    if (
        !canEdit &&
        !canDelete &&
        onReply === undefined &&
        onReact === undefined &&
        onPin === undefined &&
        onSave === undefined
    ) {
        return null;
    }

    return (
        /*
         * Revealed on hover, but also on keyboard focus — focus-within is what
         * keeps these reachable by tab rather than mouse only.
         */
        <div className="absolute top-0 right-4 flex -translate-y-1/2 items-center gap-0.5 rounded-lg border border-border bg-background p-0.5 opacity-0 shadow-sm transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
            {onReact !== undefined && <ReactionPicker onPick={onReact} />}

            {onSave !== undefined && (
                <button
                    type="button"
                    onClick={onSave}
                    aria-pressed={isSaved}
                    title={isSaved ? 'Remove from saved' : 'Save for later'}
                    className={cn(
                        'rounded p-1.5 hover:bg-accent',
                        isSaved
                            ? 'text-brand'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {isSaved ? (
                        <BookmarkCheck
                            aria-hidden="true"
                            className="size-3.5"
                        />
                    ) : (
                        <Bookmark aria-hidden="true" className="size-3.5" />
                    )}
                    <span className="sr-only">
                        {isSaved ? 'Remove from saved' : 'Save for later'}
                    </span>
                </button>
            )}

            {onPin !== undefined && (
                <button
                    type="button"
                    onClick={onPin}
                    aria-pressed={isPinned}
                    title={isPinned ? 'Unpin from channel' : 'Pin to channel'}
                    className={cn(
                        'rounded p-1.5 hover:bg-accent',
                        isPinned
                            ? 'text-brand'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {isPinned ? (
                        <PinOff aria-hidden="true" className="size-3.5" />
                    ) : (
                        <Pin aria-hidden="true" className="size-3.5" />
                    )}
                    <span className="sr-only">
                        {isPinned ? 'Unpin from channel' : 'Pin to channel'}
                    </span>
                </button>
            )}

            {onReply !== undefined && (
                <button
                    type="button"
                    onClick={onReply}
                    className="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <MessageSquareReply
                        aria-hidden="true"
                        className="size-3.5"
                    />
                    <span className="sr-only">Reply in thread</span>
                </button>
            )}

            {canEdit && (
                <button
                    type="button"
                    onClick={onEdit}
                    className="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <Pencil aria-hidden="true" className="size-3.5" />
                    <span className="sr-only">Edit message</span>
                </button>
            )}

            {canDelete && (
                <button
                    type="button"
                    onClick={onDelete}
                    className="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-danger"
                >
                    <Trash2 aria-hidden="true" className="size-3.5" />
                    <span className="sr-only">Delete message</span>
                </button>
            )}
        </div>
    );
}

/**
 * Inline editing.
 *
 * Escape cancels and Enter saves, matching the composer — an edit box that
 * behaved differently from the box directly below it would be a trap.
 */
function MessageEditor({
    initialBody,
    onSave,
    onCancel,
}: {
    initialBody: string;
    onSave: (body: string) => Promise<void>;
    onCancel: () => void;
}) {
    const [body, setBody] = useState(initialBody);
    const [saving, setSaving] = useState(false);

    const save = async () => {
        if (body.trim() === '' || saving) {
            return;
        }

        setSaving(true);

        try {
            await onSave(body);
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="mt-1 space-y-2">
            <Textarea
                value={body}
                autoFocus
                rows={2}
                aria-label="Edit message"
                onChange={(event) => setBody(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        onCancel();
                    }

                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        void save();
                    }
                }}
                className="min-h-16 resize-none"
            />

            <div className="flex items-center gap-2">
                <Button size="sm" onClick={() => void save()} disabled={saving}>
                    Save
                </Button>

                <Button
                    size="sm"
                    variant="ghost"
                    onClick={onCancel}
                    className="gap-1.5"
                >
                    <X aria-hidden="true" className="size-3.5" />
                    Cancel
                </Button>

                <span className="text-xs text-muted-foreground">
                    Escape to cancel &middot; Enter to save
                </span>
            </div>
        </div>
    );
}
