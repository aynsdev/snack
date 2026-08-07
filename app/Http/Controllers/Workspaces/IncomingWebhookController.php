<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Webhooks\CreateIncomingWebhook;
use App\Actions\Webhooks\DeleteIncomingWebhook;
use App\Actions\Webhooks\RevokeIncomingWebhook;
use App\Actions\Webhooks\UpdateIncomingWebhook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\StoreIncomingWebhookRequest;
use App\Http\Requests\Webhooks\UpdateIncomingWebhookRequest;
use App\Http\Resources\IncomingWebhookResource;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The incoming webhooks a workspace has, and the form that configures them.
 *
 * Admin-gated throughout, including the index — unusually for Snack, and for a
 * real reason: this screen shows live tokens in plaintext, so looking at it is
 * the same right as issuing one. See {@see App\Policies\IncomingWebhookPolicy}.
 *
 * Ordinary Inertia visits, like the standups screen: every action here changes
 * the page as a whole and there is nothing live on it to merge.
 */
class IncomingWebhookController extends Controller
{
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [IncomingWebhook::class, $workspace]);

        $webhooks = $workspace->webhooks()
            ->with(['channel', 'creator'])
            /*
             * Live hooks first, then newest. An integrations screen is read to
             * find the thing that is running, and a year of revoked rows above
             * it would bury that — but they stay on the page, because "what did
             * we turn off, and when" is the other question this screen answers.
             */
            ->orderByRaw('revoked_at IS NOT NULL')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('workspace/webhooks', [
            'webhooks' => IncomingWebhookResource::collection($webhooks)->resolve($request),

            /*
             * The channels a hook may be pointed at: this workspace's live ones.
             * Private channels included — an alerts hook usually belongs in the
             * on-call team's private channel — and the admin is being shown
             * names, not contents.
             */
            'channels' => $workspace->channels()
                ->notArchived()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'type'])
                ->map(fn (Channel $channel): array => [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'slug' => $channel->slug,
                    'type' => $channel->type->value,
                ])
                ->all(),

            /*
             * The payload ceilings, so the documentation panel on the page
             * quotes the numbers this installation actually enforces rather than
             * ones hardcoded into a component and free to drift from the config.
             */
            'limits' => [
                'max_text_length' => (int) config('snack.webhooks.max_text_length'),
                'max_attachments' => (int) config('snack.webhooks.max_attachments'),
                'max_fields' => (int) config('snack.webhooks.max_fields'),
                'rate_limit_per_minute' => (int) config('snack.webhooks.rate_limit_per_minute'),
            ],
        ]);
    }

    public function store(
        Workspace $workspace,
        StoreIncomingWebhookRequest $request,
        WorkspaceContext $context,
        CreateIncomingWebhook $createWebhook,
    ): RedirectResponse {
        $webhook = $createWebhook->handle(
            workspace: $workspace,
            // A resolved model, re-read through the workspace — the action is
            // never handed an id from the request.
            channel: $request->channel($context),
            creator: $request->user(),
            attributes: $this->attributes($request),
        );

        /*
         * The URL is deliberately not flashed into the session, matching
         * {@see InviteLinkController::store()}. The index re-derives it from the
         * row, so a redirect, a refresh, or a second admin opening the page all
         * show the same thing — and a live credential does not end up in a
         * session store as a side effect of a redirect.
         */
        return to_route('webhooks.index', ['workspace' => $workspace->slug])
            ->with('success', "{$webhook->name} is ready. Copy its URL below.");
    }

    public function update(
        Workspace $workspace,
        IncomingWebhook $webhook,
        UpdateIncomingWebhookRequest $request,
        UpdateIncomingWebhook $updateWebhook,
    ): RedirectResponse {
        // Authorized by the request, on the same right that created it.
        $updateWebhook->handle(
            webhook: $webhook,
            actor: $request->user(),
            attributes: $this->attributes($request),
        );

        return to_route('webhooks.index', ['workspace' => $workspace->slug]);
    }

    /**
     * Turn a hook off without removing it.
     *
     * A PATCH on the hook rather than a DELETE, because the row survives — see
     * {@see RevokeIncomingWebhook}. Its own endpoint rather than a field on
     * `update`, so the emergency action is one request that cannot be confused
     * with an edit, and so the audit entry says what actually happened.
     */
    public function revoke(
        Workspace $workspace,
        IncomingWebhook $webhook,
        Request $request,
        RevokeIncomingWebhook $revokeWebhook,
    ): RedirectResponse {
        $this->authorize('revoke', $webhook);

        $revokeWebhook->handle($webhook, $request->user());

        return to_route('webhooks.index', ['workspace' => $workspace->slug])
            ->with('success', "{$webhook->name} has been turned off. Its URL no longer works.");
    }

    public function destroy(
        Workspace $workspace,
        IncomingWebhook $webhook,
        Request $request,
        RevokeIncomingWebhook $revokeWebhook,
        DeleteIncomingWebhook $deleteWebhook,
    ): RedirectResponse {
        $this->authorize('delete', $webhook);

        /*
         * Revoked on the way out if it was still live, so there is no order of
         * operations an admin can get wrong — deleting a hook stops it, and the
         * audit log records both the stop and the removal.
         */
        $revokeWebhook->handle($webhook, $request->user());

        $deleteWebhook->handle($webhook, $request->user());

        return to_route('webhooks.index', ['workspace' => $workspace->slug]);
    }

    /**
     * The validated identity fields, in the shape both actions take.
     *
     * Spelled out rather than left as `array<string, mixed>`: it is the contract
     * between this controller and two actions, and a mixed bag would let a
     * renamed key arrive as a silently missing default.
     *
     * @return array{name: string, default_username: string|null, default_icon: string|null}
     */
    private function attributes(StoreIncomingWebhookRequest|UpdateIncomingWebhookRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            // Empty strings collapse to null: the form posts "" for a field the
            // admin cleared, and storing that would be a hook whose display name
            // is blank rather than one that falls back to its own name.
            'default_username' => $request->string('default_username')->trim()->value() ?: null,
            'default_icon' => $request->string('default_icon')->trim()->lower()->value() ?: null,
        ];
    }
}
