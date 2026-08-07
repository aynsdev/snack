<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Webhooks\DeliverWebhookMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\DeliverWebhookRequest;
use App\Models\IncomingWebhook;
use App\Support\WebhookPayload;
use Illuminate\Http\JsonResponse;

/**
 * The endpoint an outside system posts to.
 *
 * ```
 * curl -X POST -H 'Content-Type: application/json' \
 *   -d '{"text":"Deploy #482 shipped"}' \
 *   https://snack.example.com/hooks/<token>
 * ```
 *
 * # Why there is no policy call here
 *
 * Every other write path in Snack resolves a user, then asks a policy. This one
 * has no user: the token is the credential, and what it authorizes is fixed —
 * post into this hook's channel, as this hook's bot, and nothing else. The
 * lookup below *is* the authorization, which is why it is the first thing that
 * happens and why nothing downstream reads an identifier from the request.
 *
 * The channel in particular is never named by the caller. See the
 * incoming_webhooks migration for why Slack's `channel` override is the one part
 * of the legacy payload Snack does not implement.
 *
 * # Why the responses look like this
 *
 * Plain text bodies with the right status codes, because the caller is a curl
 * invocation in a deploy script whose author will read the response exactly once
 * — in a terminal, while something is broken. `ok` is Slack's own success body,
 * so a client written against theirs is satisfied by ours.
 */
class WebhookDeliveryController extends Controller
{
    /**
     * @param  string  $token  The plaintext from the URL. Never logged, and
     *                         never echoed back: an error page quoting the credential it just
     *                         rejected is a credential in somebody's CI log.
     */
    public function store(
        string $token,
        DeliverWebhookRequest $request,
        DeliverWebhookMessage $deliver,
    ): JsonResponse {
        $webhook = IncomingWebhook::findLiveByToken($token);

        /*
         * 404 rather than 401 or 403, and the same 404 for a token that never
         * existed, one that was revoked, and one whose channel has since been
         * deleted. Distinguishing them would turn this endpoint into an oracle
         * that confirms which tokens are real — and to the caller the three
         * cases are one fact anyway: this URL does not work any more.
         */
        if ($webhook === null) {
            return $this->failure('No active webhook for that URL.', 404);
        }

        /*
         * An archived channel is refused rather than posted into. Nobody is
         * reading it, so a delivery would be silently discarded from the team's
         * point of view — the caller is told plainly instead, which is what gets
         * the integration re-pointed at a live channel.
         *
         * Checked here rather than in the action because it is a property of
         * this delivery attempt: the hook is fine, and will start working again
         * the moment the channel is unarchived.
         */
        if ($webhook->channel->is_archived) {
            return $this->failure('That webhook\'s channel has been archived.', 410);
        }

        $payload = WebhookPayload::fromRequest($request->payload(), $webhook);

        if ($payload->isEmpty()) {
            // Everything the caller sent was dropped by the parser — a card of
            // nothing but a colour, say. Told rather than posted as a blank row.
            return $this->failure('That payload had nothing to post.', 422);
        }

        $message = $deliver->handle($webhook, $payload);

        /*
         * The message id is returned because it is the one piece of state the
         * caller might legitimately want, and it is not sensitive to anyone
         * already holding the token. Nothing else about the workspace is
         * disclosed — not the channel name, not the bot, not the hook's own name
         * — because a stolen URL must stay as uninformative as it is limited.
         */
        return response()->json(['ok' => true, 'message_id' => $message->id], 201);
    }

    private function failure(string $error, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error], $status);
    }
}
