<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in the workspace audit log.
 *
 * `action_label` is resolved server-side from {@see App\Enums\AuditAction} so
 * the client is not carrying a second copy of the enum that a new case would
 * silently fall out of.
 *
 * `actor` is null when the person who performed the action has since been
 * deleted. The entry deliberately outlives them — see the migration — so the
 * client renders "a deleted account" rather than dropping the row.
 *
 * Mirrors the `AuditEntry` type in resources/js/types/snack.ts.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'subject' => $this->action->subject(),

            'actor' => $this->whenLoaded(
                'user',
                fn (): ?array => $this->user === null
                    ? null
                    : (new UserResource($this->user))->resolve($request),
                null,
            ),

            /*
             * The recorded values, passed through as-is. Their shape differs per
             * action by design — a rename carries a name, a role change carries
             * a role — so the client renders them as key/value pairs rather than
             * pretending they share a schema.
             */
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,

            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
