import { Check, ClipboardList, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useWorkspace } from '@/hooks/use-workspace';
import { ApiError, api } from '@/lib/api';
import { store as submitResponse } from '@/routes/standup-responses';
import type { StandupCard as StandupCardData } from '@/types';

/**
 * The standup form, inside the bot's direct message.
 *
 * # Why a card and not a conversation
 *
 * The obvious design is the one every chat bot reaches for: ask question one,
 * wait for a reply, ask question two. It reads well in a demo and it is
 * miserable to use — the person is locked into a state machine for the next two
 * minutes, cannot see what they have already said, cannot go back and fix the
 * first answer, and any message they send for an unrelated reason is swallowed
 * as an answer. Every one of those is a bug that only exists because the bot is
 * pretending to be a person.
 *
 * A card asks all of it at once, in a form the person can look over and correct
 * before it is published to their team — which is what they are actually doing.
 *
 * # Why it renders from the message
 *
 * The card is built from `message.standup`, carried in the row itself. A
 * conversation with a month of standups in it therefore draws every card with no
 * requests at all, and the answered state survives a reload. See
 * App\Support\StandupCard.
 */
export function StandupCard({ card }: { card: StandupCardData }) {
    const workspace = useWorkspace();

    /*
     * The submitted card is held as an override, not as a seeded copy.
     *
     * `useState(card)` would freeze at first render, which was correct only
     * while this component was the sole writer. It is not any more: answering by
     * typing a reply into the DM retires the card server-side and broadcasts the
     * message, so the prop can now change underneath us — and a seeded copy
     * would go on showing the form for a standup that is already in the channel.
     */
    const [submitted, setSubmitted] = useState<StandupCardData | null>(null);

    const state = submitted ?? card;

    const answered = state.state !== 'pending';

    return (
        <section className="mt-1.5 max-w-xl overflow-hidden rounded-lg border border-border bg-card">
            <header className="flex flex-wrap items-center gap-x-2 gap-y-0.5 border-b border-border bg-muted/40 px-3 py-2">
                <ClipboardList
                    aria-hidden="true"
                    className="size-3.5 shrink-0 text-muted-foreground"
                />

                <h3 className="text-sm font-semibold">{state.name}</h3>

                <span className="text-xs text-muted-foreground">
                    {state.date_label}
                </span>

                <span className="ml-auto text-xs text-muted-foreground">
                    Posts to #{state.channel.slug}
                </span>
            </header>

            {answered ? (
                <AnsweredState card={state} />
            ) : (
                <StandupForm
                    card={state}
                    workspaceSlug={workspace.slug}
                    onSubmitted={setSubmitted}
                />
            )}
        </section>
    );
}

/**
 * The form itself.
 *
 * Split out so the answered state does not carry a dozen unused pieces of draft
 * state around with it for the rest of the conversation's life.
 */
function StandupForm({
    card,
    workspaceSlug,
    onSubmitted,
}: {
    card: StandupCardData;
    workspaceSlug: string;
    onSubmitted: (card: StandupCardData) => void;
}) {
    const [answers, setAnswers] = useState<string[]>(() =>
        card.questions.map(() => ''),
    );
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const hasSomethingToSay = answers.some((answer) => answer.trim() !== '');

    async function submit(skipped: boolean) {
        setSubmitting(true);
        setError(null);

        try {
            const payload = await api.post<{ standup: StandupCardData }>(
                submitResponse.url({
                    workspace: workspaceSlug,
                    response: card.response_id,
                }),
                { answers, skipped },
            );

            // The server's version, not the local draft: it has resolved which
            // state this counts as, dropped the blank answers, and stamped it.
            onSubmitted(payload.standup);
        } catch (failure) {
            setError(
                failure instanceof ApiError
                    ? failure.userMessage
                    : 'Could not send your standup. Try again.',
            );
            setSubmitting(false);
        }
    }

    return (
        <div className="space-y-3 px-3 py-3">
            {card.questions.map((question, index) => (
                <div key={question} className="space-y-1.5">
                    <label
                        htmlFor={`standup-${card.response_id}-${index}`}
                        className="block text-sm font-medium"
                    >
                        {question}
                    </label>

                    <Textarea
                        id={`standup-${card.response_id}-${index}`}
                        value={answers[index] ?? ''}
                        onChange={(event) =>
                            setAnswers((current) =>
                                current.map((answer, position) =>
                                    position === index
                                        ? event.target.value
                                        : answer,
                                ),
                            )
                        }
                        rows={2}
                        maxLength={2000}
                        disabled={submitting}
                        className="min-h-16 resize-y text-sm"
                    />
                </div>
            ))}

            {error !== null && (
                <p role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={submitting || !hasSomethingToSay}
                    onClick={() => submit(false)}
                >
                    {submitting && (
                        <Loader2
                            aria-hidden="true"
                            className="mr-1.5 size-3.5 animate-spin"
                        />
                    )}
                    Send to #{card.channel.slug}
                </Button>

                {/*
                 * "Nothing to report" is a first-class answer with its own
                 * button, not an empty submission.
                 *
                 * The person with nothing to say is exactly the one the channel
                 * most needs to hear from, because otherwise they are
                 * indistinguishable from somebody who is off sick — and making
                 * them type "nothing" into three boxes to say so is how a
                 * standup stops being answered by Thursday.
                 */}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={submitting}
                    onClick={() => submit(true)}
                >
                    Nothing to report
                </Button>
            </div>
        </div>
    );
}

/**
 * What the card becomes once it has been sent.
 *
 * It keeps the answers rather than collapsing to a tick: this conversation is
 * the only place the person can see what they said, and a standup they sent an
 * hour ago is a thing people go back and check.
 */
function AnsweredState({ card }: { card: StandupCardData }) {
    const answers = card.answers ?? [];

    return (
        <div className="space-y-2.5 px-3 py-3">
            <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                <Check aria-hidden="true" className="size-3.5" />
                {card.state === 'skipped'
                    ? `Sent to #${card.channel.slug} — nothing to report.`
                    : `Sent to #${card.channel.slug}.`}
            </p>

            {answers.map((answer) => (
                <div key={answer.question} className="space-y-0.5">
                    <p className="text-xs text-muted-foreground">
                        {answer.question}
                    </p>
                    <p className="text-sm whitespace-pre-wrap">
                        {answer.answer}
                    </p>
                </div>
            ))}
        </div>
    );
}
