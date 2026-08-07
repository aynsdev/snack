<?php

namespace App\Http\Resources;

use App\Models\IncomingWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A hook as the admin screen sees it.
 *
 * Mirrors the `IncomingWebhook` type in resources/js/types/snack.ts.
 *
 * # The one resource in Snack that publishes a credential
 *
 * `url` contains the live token in plaintext. It is here because there is
 * nowhere else it could come from — a hook's URL has no delivery mechanism other
 * than this screen, the same bind {@see WorkspaceInvitationResource} is in with
 * an invite link — and because a "copy URL" button that only worked in the
 * minute after creation would have every admin storing the thing in a notes app
 * instead.
 *
 * What keeps that from being careless is where it is allowed to be built:
 * {@see App\Policies\IncomingWebhookPolicy::viewAny()} gates the whole screen on
 * administering the workspace, precisely so that reading this field is the same
 * right as issuing one. Do not resolve this resource anywhere that check has not
 * just run.
 *
 * A revoked hook returns null instead. The row is kept so the incident stays
 * readable, but its URL is dead, and rendering a dead credential invites
 * somebody to paste it into a config and wonder why nothing arrives.
 *
 * @mixin IncomingWebhook
 */
class IncomingWebhookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            'channel' => [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
                'slug' => $this->channel->slug,
            ],

            'default_username' => $this->default_username,
            'default_icon' => $this->default_icon,

            // What messages from this hook are actually attributed to, resolved
            // once here so the list and the dialog cannot disagree about it.
            'display_name' => $this->resource->displayName(),

            'url' => $this->resource->isRevoked() ? null : $this->resource->url(),

            'is_revoked' => $this->resource->isRevoked(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),

            /*
             * The two numbers an admin opens this screen for. "It stopped
             * working" is nearly always answered by one of them: never fired at
             * all (the URL was never wired up), or last fired three weeks ago
             * (something on the other end broke).
             */
            'delivery_count' => $this->delivery_count,
            'last_delivered_at' => $this->last_delivered_at?->toIso8601String(),

            'created_by' => $this->whenLoaded(
                'creator',
                fn (): ?array => $this->resource->creator === null ? null : [
                    'id' => $this->resource->creator->id,
                    'name' => $this->resource->creator->name,
                ],
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
