import { Bot, MessageSquare } from 'lucide-react';
import { useState } from 'react';
import { MetaBadge } from '@/components/snack/meta-badge';
import { UserAvatar } from '@/components/snack/user-avatar';
import { Button } from '@/components/ui/button';
import type { TaskComment } from '@/types';

/**
 * The discussion on a card.
 *
 * Oldest first, unlike a channel: a card is read from the top to find out how it
 * got here, where a room is joined at the end. The composer sits at the bottom
 * for the same reason — the next remark follows the last one.
 *
 * Plain text, deliberately. The message composer's markers belong to messages,
 * which have a parser behind them; offering the same toolbar here would render
 * `**bold**` literally to whoever reads it next.
 */
export function TaskComments({
    comments,
    canContribute,
    isLoading,
    isSaving,
    onAdd,
    onEdit,
    onRemove,
}: {
    comments: TaskComment[];
    canContribute: boolean;
    isLoading: boolean;
    isSaving: boolean;
    onAdd: (body: string) => void;
    onEdit: (id: number, body: string) => void;
    onRemove: (id: number) => void;
}) {
    const [draft, setDraft] = useState('');

    const submit = () => {
        const body = draft.trim();

        if (body === '' || isSaving) {
            return;
        }

        setDraft('');
        onAdd(body);
    };

    return (
        <section className="mt-6 border-t border-border pt-4">
            <h3 className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                <MessageSquare aria-hidden="true" className="size-3.5" />
                Comments
                {comments.length > 0 && (
                    <span className="tabular-nums">({comments.length})</span>
                )}
            </h3>

            {isLoading ? (
                <div className="mt-3 space-y-2" aria-hidden="true">
                    <div className="h-9 animate-pulse rounded-lg bg-muted/60" />
                    <div className="h-9 w-3/4 animate-pulse rounded-lg bg-muted/60" />
                </div>
            ) : (
                <ul className="mt-3 space-y-3">
                    {comments.map((comment) => (
                        <CommentRow
                            key={comment.id}
                            comment={comment}
                            onEdit={onEdit}
                            onRemove={onRemove}
                        />
                    ))}
                </ul>
            )}

            {canContribute && (
                <div className="mt-3">
                    <textarea
                        value={draft}
                        rows={2}
                        placeholder="Add a comment…"
                        aria-label="Add a comment"
                        onChange={(event) => setDraft(event.target.value)}
                        onKeyDown={(event) => {
                            // Enter alone starts a new line. A comment on a card
                            // is usually a paragraph, not a chat line, and the
                            // cost of losing one halfway is higher than a click.
                            if (
                                event.key === 'Enter' &&
                                (event.metaKey || event.ctrlKey)
                            ) {
                                event.preventDefault();
                                submit();
                            }
                        }}
                        className="w-full resize-y rounded-lg border border-border bg-background p-2.5 text-sm outline-none focus:border-brand"
                    />

                    <div className="mt-1.5 flex items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            disabled={draft.trim() === '' || isSaving}
                            onClick={submit}
                        >
                            Comment
                        </Button>

                        <span className="text-xs text-muted-foreground">
                            ⌘↵ to send
                        </span>
                    </div>
                </div>
            )}

            {!canContribute && comments.length === 0 && !isLoading && (
                <p className="mt-2 text-sm text-muted-foreground">
                    Nothing has been said about this card yet.
                </p>
            )}
        </section>
    );
}

function CommentRow({
    comment,
    onEdit,
    onRemove,
}: {
    comment: TaskComment;
    onEdit: (id: number, body: string) => void;
    onRemove: (id: number) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(comment.body);
    const [confirming, setConfirming] = useState(false);

    return (
        <li className="flex gap-2.5">
            {comment.author === null ? (
                <span className="size-7 shrink-0 rounded-full bg-muted" />
            ) : (
                <UserAvatar user={comment.author} size="sm" />
            )}

            <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-baseline gap-x-2 text-xs text-muted-foreground">
                    <span className="font-semibold text-foreground">
                        {/* A deleted account leaves its comments behind. */}
                        {comment.author?.name ?? 'Former member'}
                    </span>

                    {comment.agent !== null && (
                        <MetaBadge
                            icon={Bot}
                            title={`Written by ${comment.agent.name.trim() || 'an agent'}, working for ${comment.author?.name ?? 'this person'}`}
                        >
                            Agent
                        </MetaBadge>
                    )}

                    {comment.created_at !== null && (
                        <time dateTime={comment.created_at}>
                            {new Date(comment.created_at).toLocaleString()}
                        </time>
                    )}

                    {comment.edited_at !== null && <span>(edited)</span>}
                </p>

                {editing ? (
                    <div className="mt-1">
                        <textarea
                            value={draft}
                            rows={3}
                            aria-label="Edit comment"
                            onChange={(event) => setDraft(event.target.value)}
                            className="w-full resize-y rounded-lg border border-border bg-background p-2.5 text-sm outline-none focus:border-brand"
                        />

                        <div className="mt-1.5 flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                disabled={draft.trim() === ''}
                                onClick={() => {
                                    onEdit(comment.id, draft.trim());
                                    setEditing(false);
                                }}
                            >
                                Save
                            </Button>

                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    setDraft(comment.body);
                                    setEditing(false);
                                }}
                            >
                                Cancel
                            </Button>
                        </div>
                    </div>
                ) : (
                    <p className="mt-0.5 text-sm whitespace-pre-wrap">
                        {comment.body}
                    </p>
                )}

                {!editing && (comment.can.update || comment.can.delete) && (
                    <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                        {comment.can.update && (
                            <button
                                type="button"
                                onClick={() => {
                                    setDraft(comment.body);
                                    setEditing(true);
                                }}
                                className="transition-colors hover:text-foreground"
                            >
                                Edit
                            </button>
                        )}

                        {comment.can.delete &&
                            (confirming ? (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => onRemove(comment.id)}
                                        className="text-destructive"
                                    >
                                        Delete for everyone
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setConfirming(false)}
                                        className="transition-colors hover:text-foreground"
                                    >
                                        Keep
                                    </button>
                                </>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setConfirming(true)}
                                    className="transition-colors hover:text-destructive"
                                >
                                    Delete
                                </button>
                            ))}
                    </div>
                )}
            </div>
        </li>
    );
}
