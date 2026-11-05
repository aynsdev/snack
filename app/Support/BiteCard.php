<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;

/**
 * The structured block a Bite message carries when it is waiting to be
 * approved, and the client renders as a card with buttons.
 *
 * # Why the message carries it instead of the client fetching it
 *
 * The same reason {@see StandupCard} does. A message scrolling into view has to
 * draw its card without a request of its own, and the card has to survive a
 * reload, which client-side state would not. So it rides on the message, in
 * `metadata`, and is rewritten in place when the decision is made — see
 * {@see App\Http\Controllers\Ai\BiteApprovalController}.
 *
 * # What is deliberately not in here
 *
 * The tool's raw arguments, in full. What the card shows is a summary the
 * person can check — the title, the project — because the point of the pause is
 * that they can see the model got it right, and a JSON blob is not something
 * anybody checks. The arguments that actually run are the ones the SDK stored
 * with the paused call; nothing the browser sends back can change them, which
 * is why the decision endpoint takes only "yes" or "no".
 */
final readonly class BiteCard
{
    /**
     * Build the card for a response that paused.
     *
     * @param  Collection<int, PendingApproval>|array<int, PendingApproval>  $approvals
     * @param  int|null  $requestMessageId  The message that asked, so the resume
     *                                      can hand it back to the tool — a task
     *                                      filed from it takes its files along.
     * @return array<string, mixed>
     */
    public static function forApprovals(?string $conversationId, Collection|array $approvals, ?int $requestMessageId = null): array
    {
        $approvals = Collection::make($approvals);

        if ($conversationId === null || $approvals->isEmpty()) {
            /*
             * A pause with no conversation to resume into cannot be approved,
             * so it must not render a button that pretends otherwise. Returning
             * nothing leaves an ordinary message, which is the honest fallback.
             */
            return [];
        }

        return [
            'bite' => [
                'conversation_id' => $conversationId,
                'request_message_id' => $requestMessageId,
                'state' => 'pending',
                'approvals' => $approvals->map(fn (PendingApproval $approval): array => [
                    'id' => $approval->id,
                    'tool' => $approval->tool,
                    'reason' => $approval->reason,
                    'summary' => self::summarize($approval),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * The card as it looks once somebody has decided.
     *
     * Keyed the same and written over the original so the timeline redraws the
     * same message rather than growing a second one, and so a reload after the
     * click shows the outcome rather than the buttons again.
     *
     * @param  array<string, mixed>  $existing  The card currently on the message.
     * @return array<string, mixed>
     */
    public static function resolved(array $existing, string $state, ?string $outcome = null): array
    {
        $card = $existing['bite'] ?? [];

        return [
            'bite' => [
                ...$card,
                'state' => $state,
                'outcome' => $outcome,
                // The buttons are gone, so the ids they submitted are no longer
                // useful to the client — and leaving them invites a second POST
                // that the SDK would reject as already resolved.
                'approvals' => [],
            ],
        ];
    }

    /**
     * A human sentence for one paused call.
     *
     * The tool's own reason where it gave one — {@see App\Ai\Tools\CreateTask}
     * writes the title and project into it precisely so this line is worth
     * reading. The fallback names the tool, which is not useful but is at least
     * not misleading.
     */
    private static function summarize(PendingApproval $approval): string
    {
        if ($approval->reason !== null && $approval->reason !== '') {
            return $approval->reason;
        }

        return "Run {$approval->tool}.";
    }
}
