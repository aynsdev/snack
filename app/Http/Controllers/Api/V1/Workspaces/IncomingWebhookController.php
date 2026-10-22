<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Actions\Webhooks\CreateIncomingWebhook;
use App\Actions\Webhooks\DeleteIncomingWebhook;
use App\Actions\Webhooks\RestoreIncomingWebhook;
use App\Actions\Webhooks\RevokeIncomingWebhook;
use App\Actions\Webhooks\UpdateIncomingWebhook;
use App\Actions\Webhooks\UpdateWebhookSigning;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\StoreIncomingWebhookRequest;
use App\Http\Requests\Webhooks\UpdateIncomingWebhookRequest;
use App\Http\Requests\Webhooks\UpdateWebhookSigningRequest;
use App\Http\Resources\IncomingWebhookResource;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Incoming webhooks — the mirror of
 * {@see \App\Http\Controllers\Workspaces\IncomingWebhookController}. Admin-gated
 * throughout, the index included, because a hook's `url` (and `signing_secret`)
 * is a live credential. Every write answers with the hook as it now stands, so
 * a new URL or secret reaches the app in the response that made it.
 */
class IncomingWebhookController extends Controller
{
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [IncomingWebhook::class, $workspace]);

        $webhooks = $workspace->webhooks()
            ->with(['channel', 'creator'])
            // Live hooks first, then newest.
            ->orderByRaw('revoked_at IS NOT NULL')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'webhooks' => IncomingWebhookResource::collection($webhooks)->resolve($request),
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
                'limits' => [
                    'max_text_length' => (int) config('snack.webhooks.max_text_length'),
                    'max_attachments' => (int) config('snack.webhooks.max_attachments'),
                    'max_fields' => (int) config('snack.webhooks.max_fields'),
                    'rate_limit_per_minute' => (int) config('snack.webhooks.rate_limit_per_minute'),
                ],
            ],
        ]);
    }

    public function store(
        Workspace $workspace,
        StoreIncomingWebhookRequest $request,
        WorkspaceContext $context,
        CreateIncomingWebhook $createWebhook,
    ): JsonResponse {
        $webhook = $createWebhook->handle(
            workspace: $workspace,
            channel: $request->channel($context),
            creator: $request->user(),
            attributes: $this->attributes($request),
        );

        return $this->present($webhook)->response()->setStatusCode(201);
    }

    public function update(
        Workspace $workspace,
        IncomingWebhook $webhook,
        UpdateIncomingWebhookRequest $request,
        UpdateIncomingWebhook $updateWebhook,
    ): IncomingWebhookResource {
        $updateWebhook->handle(
            webhook: $webhook,
            actor: $request->user(),
            attributes: $this->attributes($request),
        );

        return $this->present($webhook);
    }

    public function revoke(
        Workspace $workspace,
        IncomingWebhook $webhook,
        Request $request,
        RevokeIncomingWebhook $revokeWebhook,
    ): IncomingWebhookResource {
        $this->authorize('revoke', $webhook);

        $revokeWebhook->handle($webhook, $request->user());

        return $this->present($webhook);
    }

    public function restore(
        Workspace $workspace,
        IncomingWebhook $webhook,
        Request $request,
        RestoreIncomingWebhook $restoreWebhook,
    ): IncomingWebhookResource {
        $this->authorize('restore', $webhook);

        $restoreWebhook->handle($webhook, $request->user());

        return $this->present($webhook);
    }

    /**
     * `mode` is enable, disable or rotate; the answer carries the new
     * `signing_secret` for the first and last.
     */
    public function updateSigning(
        Workspace $workspace,
        IncomingWebhook $webhook,
        UpdateWebhookSigningRequest $request,
        UpdateWebhookSigning $updateSigning,
    ): IncomingWebhookResource {
        match ($request->mode()) {
            UpdateWebhookSigningRequest::ENABLE => $updateSigning->enable($webhook, $request->user()),
            UpdateWebhookSigningRequest::DISABLE => $updateSigning->disable($webhook, $request->user()),
            default => $updateSigning->rotate($webhook, $request->user()),
        };

        return $this->present($webhook);
    }

    public function destroy(
        Workspace $workspace,
        IncomingWebhook $webhook,
        Request $request,
        RevokeIncomingWebhook $revokeWebhook,
        DeleteIncomingWebhook $deleteWebhook,
    ): Response {
        $this->authorize('delete', $webhook);

        // Revoked on the way out, so the audit log records the stop as well.
        $revokeWebhook->handle($webhook, $request->user());

        $deleteWebhook->handle($webhook, $request->user());

        return response()->noContent();
    }

    private function present(IncomingWebhook $webhook): IncomingWebhookResource
    {
        return new IncomingWebhookResource($webhook->refresh()->load(['channel', 'creator']));
    }

    /**
     * @return array{name: string, default_username: string|null, default_icon: string|null}
     */
    private function attributes(StoreIncomingWebhookRequest|UpdateIncomingWebhookRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'default_username' => $request->string('default_username')->trim()->value() ?: null,
            'default_icon' => $request->string('default_icon')->trim()->lower()->value() ?: null,
        ];
    }
}
