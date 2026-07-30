import { Pencil, RotateCw, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { MessageText } from '@/components/snack/message-text';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { Message, PendingMessage } from '@/types';

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
}: {
    message: Message;
    grouped: boolean;
    isOwn: boolean;
    onEdit: (id: number, body: string) => Promise<void>;
    onDelete: (id: number) => void;
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
                    <time
                        dateTime={message.created_at ?? undefined}
                        className="hidden text-[0.625rem] leading-5 text-muted-foreground tabular-nums group-hover:block"
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
                            {message.sender?.name ?? 'Snack'}
                        </span>

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
                        <MessageText body={message.body} />

                        {message.is_edited && (
                            <span className="ml-1 text-[0.6875rem] text-muted-foreground">
                                (edited)
                            </span>
                        )}
                    </>
                )}
            </div>

            {!editing && (canEdit || canDelete) && (
                <MessageActions
                    canEdit={canEdit}
                    canDelete={canDelete}
                    onEdit={() => setEditing(true)}
                    onDelete={() => onDelete(message.id)}
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

                <MessageText
                    body={message.body}
                    className={message.failed ? 'text-danger' : 'opacity-60'}
                />

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

function MessageActions({
    canEdit,
    canDelete,
    onEdit,
    onDelete,
}: {
    canEdit: boolean;
    canDelete: boolean;
    onEdit: () => void;
    onDelete: () => void;
}) {
    return (
        /*
         * Revealed on hover, but also on keyboard focus — focus-within is what
         * keeps these reachable by tab rather than mouse only.
         */
        <div className="absolute top-0 right-4 flex -translate-y-1/2 items-center gap-0.5 rounded-lg border border-border bg-background p-0.5 opacity-0 shadow-sm transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
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
